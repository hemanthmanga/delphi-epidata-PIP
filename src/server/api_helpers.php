<?php
// load database connection parameters
require_once(__DIR__ . '/database_config.php');

// connects to the database
function database_connect() {
  global $DATABASE_CONFIG;
  $host = $DATABASE_CONFIG['host'];
  $port = $DATABASE_CONFIG['port'];
  $username = Secrets::$db['epi'][0];
  $password = Secrets::$db['epi'][1];
  $database = 'epidata';
  // bind database handle to global; could also pass around
  global $dbh;
  $dbh = mysqli_connect($host, $username, $password, $database, $port);
  return $dbh;
}

// returns true if all fields are present in the request
//   $output: output array to set an error message
//   $values: an array of field names
function require_all(APrinter &$printer, $values) {
  foreach($values as $value) {
    if(!isset($_REQUEST[$value])) {
      $printer->printValidationFailed('missing parameter: need [' . $value . ']');
      return false;
    }
  }
  return true;
}

// returns true if any fields are present in the request
//   $output: output array to set an error message
//   $values: an array of field names
function require_any(APrinter &$printer, $values) {
  foreach($values as $value) {
    if(isset($_REQUEST[$value])) {
      return true;
    }
  }
  $printer->printValidationFailed('missing parameter: need one of [' . implode(', ', $values) . ']');
  return false;
}

// converts a date integer (YYYYMMDD) into a date string (YYYY-MM-DD)
//   $value: the date as an 8-digit integer
function date_string($value) {
  $year = intval($value / 10000) % 10000;
  $month = intval($value / 100) % 100;
  $day = $value % 100;
  return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

// builds a SQL expression to filter values/ranges of dates
//   $field: name of the field to filter
//   $dates: array of date values/ranges
function filter_dates($field, $dates) {
  $filter = null;
  foreach($dates as $date) {
    if($filter === null) {
      $filter = '';
    } else {
      $filter .= ' OR ';
    }
    if(is_array($date)) {
      // range of values
      $first = date_string($date[0]);
      $last = date_string($date[1]);
      $filter .= "({$field} BETWEEN '{$first}' AND '{$last}')";
    } else {
      // single value
      $date = date_string($date);
      $filter .= "({$field} = '{$date}')";
    }
  }
  return $filter;
}

// builds a SQL expression to filter values/ranges of integers (ex: epiweeks)
//   $field: name of the field to filter
//   $values: array of integer values/ranges
function filter_integers($field, $values) {
  $filter = null;
  foreach($values as $value) {
    if($filter === null) {
      $filter = '';
    } else {
      $filter .= ' OR ';
    }
    if(is_array($value)) {
      // range of values
      $filter .= "({$field} BETWEEN {$value[0]} AND {$value[1]})";
    } else {
      // single value
      $filter .= "({$field} = {$value})";
    }
  }
  return $filter;
}

// builds a SQL expression to filter strings (ex: locations)
//   $field: name of the field to filter
//   $values: array of values
function filter_strings($field, $values) {
  global $dbh;
  $filter = null;
  foreach($values as $value) {
    if($filter === null) {
      $filter = '';
    } else {
      $filter .= ' OR ';
    }
    if(is_array($value)) {
      // range of values
      $value0 = mysqli_real_escape_string($dbh, $value[0]);
      $value1 = mysqli_real_escape_string($dbh, $value[1]);
      $filter .= "({$field} BETWEEN '{$value0}' AND '{$value1}')";
    } else {
      // single value
      $value = mysqli_real_escape_string($dbh, $value);
      $filter .= "({$field} = '{$value}')";
    }
  }
  return $filter;
}

// extracts an array of values and/or ranges from a string
//   $str: the string to parse
//   $type:
//     - 'int': interpret dashes as ranges, cast values to integers
//     - 'ordered_string': interpret dashes as ranges, keep values as strings
//     - otherwise: ignore dashes, keep values as strings
function extract_values($str, $type) {
  if($str === null || strlen($str) === 0) {
    // nothing to do
    return null;
  }
  // whether to parse a value with a dash as a range of values
  $shouldParseRange = $type === 'int' || $type === 'ordered_string';
  // maintain a list of values and/or ranges
  $values = array();
  // split on commas and loop over each entry, which could be either a single value or a range of values
  $parts = explode(',', $str);
  foreach($parts as $part) {
    if($shouldParseRange && strpos($part, '-') !== false) {
      // split on the dash
      $range = explode('-', $part);
      // get the range endpoints
      $first = $range[0];
      $last = $range[1];
      if ($type === 'int') {
        $first = intval($first);
        $last = intval($last);
      }
      if($last === $first) {
        // the first and last numbers are the same, just treat it as a singe value
        array_push($values, $first);
      } else if($last > $first) {
        // add the range as an array
        array_push($values, array($first, $last));
      } else {
        // the range is inverted, this is an error
        return null;
      }
    } else {
      // this is a single value
      if($type === 'int') {
        // cast to integer
        $value = intval($part);
      } else {
        // interpret the string literally
        $value = $part;
      }
      // add the extracted value to the list
      array_push($values, $value);
    }
  }
  // success, return the list
  return $values;
}

/**
 * parses a given string in format YYYYMMDD or YYYY-MM-DD to a number in the form YYYYMMDD
 */
function parse_date($s) {
  return intval(str_replace('-', '', $s));
}

// extracts an array of values and/or ranges from a string
//   $str: the string to parse
function extract_dates($str) {
  if($str === null || strlen($str) === 0) {
    // nothing to do
    return null;
  }
  $values = array();
  // split on commas and loop over each entry, which could be either a single value or a range of values
  $parts = explode(',', $str);

  $push_range = function($first, $last) {
    $first = parse_date($first);
    $last = parse_date($last);
    if($last === $first) {
      // the first and last numbers are the same, just treat it as a singe value
      return $first;
    }
    if($last > $first) {
      // add the range as an array
      return array($first, $last);
    }
    // the range is inverted, this is an error
    return false;
  };

  foreach($parts as $part) {
    if(strpos($part, '-') === false && strpos($part, ':') === false) {
      // YYYYMMDD
      array_push($values, parse_date($part));
      continue;
    }
    if (strpos($part, ':') !== false) {
      // YYYY-MM-DD:YYYY-MM-DD
      $range = explode(':', $part);
      $r = $push_range($range[0], $range[1]);
      if ($r === false) {
        return null;
      }
      array_push($values, $r);
    }
    // YYYY-MM-DD or YYYYMMDD-YYYYMMDD
    // split on the dash
    $range = explode('-', $part);
    if (count($range) === 2) {
      // YYYYMMDD-YYYYMMDD
      $r = $push_range($range[0], $range[1]);
      if ($r === false) {
        return null;
      }
      array_push($values, $r);
      continue;
    }
    // YYYY-MM-DD
    array_push($values, parse_date($part));
  }
  // success, return the list
  return $values;
}

// give a comma-separated, quoted list of states in an HHS or Census region
function get_region_states($region) {
  switch($region) {
    case 'hhs1': return "'VT', 'CT', 'ME', 'MA', 'NH', 'RI'";
    case 'hhs2': return "'NJ', 'NY'";
    case 'hhs3': return "'DE', 'DC', 'MD', 'PA', 'VA', 'WV'";
    case 'hhs4': return "'AL', 'FL', 'GA', 'KY', 'MS', 'NC', 'TN', 'SC'";
    case 'hhs5': return "'IL', 'IN', 'MI', 'MN', 'OH', 'WI'";
    case 'hhs6': return "'AR', 'LA', 'NM', 'OK', 'TX'";
    case 'hhs7': return "'IA', 'KS', 'MO', 'NE'";
    case 'hhs8': return "'CO', 'MT', 'ND', 'SD', 'UT', 'WY'";
    case 'hhs9': return "'AZ', 'CA', 'HI', 'NV'";
    case 'hhs10': return "'AK', 'ID', 'OR', 'WA'";
    case 'cen1': return "'CT', 'ME', 'MA', 'NH', 'RI', 'VT'";
    case 'cen2': return "'NJ', 'NY', 'PA'";
    case 'cen3': return "'IL', 'IN', 'MI', 'OH', 'WI'";
    case 'cen4': return "'IA', 'KS', 'MN', 'MO', 'NE', 'ND', 'SD'";
    case 'cen5': return "'DE', 'DC', 'FL', 'GA', 'MD', 'NC', 'SC', 'VA', 'WV'";
    case 'cen6': return "'AL', 'KY', 'MS', 'TN'";
    case 'cen7': return "'AR', 'LA', 'OK', 'TX'";
    case 'cen8': return "'AZ', 'CO', 'ID', 'MT', 'NV', 'NM', 'UT', 'WY'";
    case 'cen9': return "'AK', 'CA', 'HI', 'OR', 'WA'";
  }
  return null;
}

function record_analytics($source, $result, $num_rows = 0) {
  global $dbh;
  $ip = mysqli_real_escape_string($dbh, isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '');
  $ua = mysqli_real_escape_string($dbh, isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '');
  $source = mysqli_real_escape_string($dbh, isset($source) ? $source : '');
  mysqli_query($dbh, "INSERT INTO `api_analytics` (`datetime`, `ip`, `ua`, `source`, `result`, `num_rows`) VALUES (now(), '{$ip}', '{$ua}', '{$source}', {$result}, {$num_rows})");
}

// executes a query, casts the results, and returns an array of the data
// the number of results is limited to $MAX_RESULTS
//   $query (required): a SQL query string
//   $printer (required): the row printer to use
//   $fields_string (optional): an array of names of string fields
//   $fields_int (optional): an array of names of integer fields
//   $fields_float (optional): an array of names of float fields
function execute_query(string $query, IRowPrinter &$printer, ?array $fields_string, ?array $fields_int, ?array $fields_float, bool $single_query = TRUE) {
  global $dbh;
  global $MAX_RESULTS;
  error_log($query);
  $result = mysqli_real_query($dbh, $query . " LIMIT {$MAX_RESULTS}");
  if (!$result) {
    error_log("Bad query: ".$query);
    error_log(mysqli_error($dbh));
    return;
  }
  $result = mysqli_use_result($dbh);

  if (isset($_REQUEST['fields'])) {
    $fields = extract_values($_REQUEST['fields'], 'str');
    // limit fields to the selection
    if($fields_string !== null) {
      $fields_string = array_intersect($fields_string, $fields);
    }
    if($fields_int !== null) {
      $fields_int = array_intersect($fields_int, $fields);
    }
    if($fields_float !== null) {
      $fields_float = array_intersect($fields_float, $fields);
    }
  }

  $printer->begin();

  while ($row = mysqli_fetch_row($result)) {
    $values = array();
    if($fields_string !== null) {
      foreach($fields_string as $field) {
        $values[$field] = $row[$field];
      }
    }
    if($fields_int !== null) {
      foreach($fields_int as $field) {
        if($row[$field] === null) {
          $values[$field] = null;
        } else {
          $values[$field] = intval($row[$field]);
        }
      }
    }
    if($fields_float !== null) {
      foreach($fields_float as $field) {
        if($row[$field] === null) {
          $values[$field] = null;
        } else {
          $values[$field] = floatval($row[$field]);
        }
      }
    }
    $printer->printRow($values);
  }
  mysqli_free_result($result);

  if ($single_query) {
    // unless we are going to append more, we end the printing
    $printer->end();
  }
}

function execute_query_append(string $query, IRowPrinter &$printer, ?array $fields_string, ?array $fields_int, ?array $fields_float) {
  return execute_query($query, $printer, $fields_string, $fields_int, $fields_float, FALSE);
}

interface IRowPrinter {
  public function begin();
  public function printRow(array &$row);
  public function end();
}

class CollectRowPrinter implements IRowPrinter {
  public array $data = [];

  public function begin() {
    // dummy
  }
  public function printRow(array &$row) {
    array_push($this->data, $row);
  }
  public function end() {
    // dummy
  }
}

abstract class APrinter implements IRowPrinter {
  public int $count = 0;
  public int $result = -1;
  protected bool $began = FALSE;
  protected bool $useStatusCodes = TRUE;
  public string $source;

  function __construct(string $source, bool $useStatusCodes = TRUE) {
    $this->source = $source;
    $this->useStatusCodes = $useStatusCodes;
  }

  public function printError(int $result, string $message, int $statusCode = -1) {
    $this->result = $result;
    if ($statusCode >= 0 && $this->useStatusCodes) {
      http_response_code($statusCode);
    }
    header('Content-Type: application/json');
    echo json_encode(array('result' => $result, 'message' => $message));
    record_analytics($this->source, $this->result);
  }

  public function printDatabaseError() {
    $this->printError(-1, 'database error', 500);
  }

  public function printUnAuthenticated() {
    $this->printError(-1, 'unauthenticated', 401);
  }

  public function printValidationFailed(string $message) {
    $this->printError(-1, $message, 400);
  }

  public function printMissingOrWrongSource() {
    $this->printError(-1, 'no data source specified', 400);    
  }

  public function printNonStandard(mixed &$data) {
    $this->result = 1;
    header('Content-Type: application/json');
    echo json_encode(array('result' => $this->result, 'message' => 'success', 'epidata' => $data));
    record_analytics($this->source, $this->result);
  }

  public function begin() {
    if ($this->began) {
      return;
    }
    $this->began = TRUE;
    $this->beginImpl();
  }

  protected function beginImpl() {
    // hook
  }

  public function printRow(array &$row) {
    if (!$this->began) {
      $this->begin();
    }
    $first = $this->count == 0;
    $this->printRowImpl($first, $row);
    $this->count++;
  }

  protected abstract function printRowImpl(bool $first, array &$row);

  public function end(bool $hasMore = FALSE) {
    if (!$this->began) {
      return;
    }
    $this->result = $this->count == 0 ? -2 : ($hasMore ? 2 : 1);
    $this->endImpl();
    record_analytics($this->source, $this->result, $this->count);
  }

  protected function endImpl() {
    // hook
  }
}

class ClassicPrinter extends APrinter {

  function __construct(string $source) {
    parent::__construct($source, FALSE);
  }

  protected function beginImpl() {
    header('Content-Type: application/json');
    echo '{ "epidata": [';
  }

  protected function printRowImpl(bool $first, array &$row) {
    if (!$first) {
      echo ',';
    }
    echo json_encode($row);
  }

  protected function endImpl() {
    $message = $this->count == 0 ? 'no results' : ($this->result == 2 ? 'too many results, data truncated' : 'success');
    $messageEncoded = json_encode($message);
    echo "], \"count\": {$this->count}, \"result\": {$this->result}, \"message\": {$messageEncoded} }";
  }
}

class ClassicTreePrinter extends ClassicPrinter {
  private array $data = [];
  private string $group;

  function __construct(string $source, string $group) {
    parent::__construct($source);
    $this->group = $group;
  }

  protected function printRowImpl(bool $first, array &$row) {
    array_push($this->data, $row);
  }

  private function printTree() {
    // compute tree and print single row
    $epi_tree = [];

    foreach ($this->data as $row) {
      $group = isset($row[$this->group]) ? $row[$this->group] : '';
      unset($row[$this->group]);      
      if (isset($epi_tree[$group])) {
        array_push($epi_tree[$group], $row);
      } else {
        $epi_tree[$group] = [$row];
      }
    }
    // clean up
    $this->data = [];

    if (count($epi_tree) == 0) {
      echo '{}'; // force object style
    } else {
      echo json_encode($epi_tree);
    }
  }

  protected function endImpl() {
    $this->printTree();
    parent::endImpl();
  }
}

class CSVPrinter extends APrinter {
  private $out = null;

  protected function beginImpl() {
    $this->out = fopen('php://output', 'w');
    header('Content-Type: text/csv; charset=utf8');
    header('Content-Disposition: attachment; filename=epidata.csv');
  }

  protected function printRowImpl(bool $first, array &$row) {
    if ($first) {
      // print headers
      $headers = array_keys($row);
      fputcsv($this->out, $headers);  
    }
    fputcsv($this->out, $row);
  }

  protected function endImpl() {
    fclose($this->out);
  }
}

class JSONPrinter extends APrinter {
  protected function beginImpl() {
    header('Content-Type: application/json');
    echo '[';
  }

  protected function printRowImpl(bool $first, array &$row) {
    if (!$first) {
      echo ',';
    }
    echo json_encode($row);
  }

  protected function endImpl() {
    echo ']';
  }
}

class JSONLPrinter extends APrinter {
  protected function beginImpl() {
    // there is no official mime type for json lines
    header('Content-Type: text/plain; charset=utf8');
  }

  protected function printRowImpl(bool $first, array &$row) {
    echo json_encode($row);
    echo "\n";
  }
}

function createPrinter(string $source, string $format = 'classic') {
  switch($format) {
  case 'tree':
    return new ClassicTreePrinter($source, 'signal');
  case 'json': 
    return new JSONPrinter($source);
  case 'csv': 
    return new CSVPrinter($source);
  case 'jsonl':
    return new JSONLPrinter($source);
  default:
    return new ClassicPrinter($source);
  }
}

?>
