from dataclasses import dataclass, asdict
from typing import Optional, Iterable, Tuple
from datetime import date
from enum import Enum


class TrendEnum(str, Enum):
    unknown = "unknown"
    increasing = "increasing"
    decreasing = "decreasing"
    steady = "steady"


@dataclass
class Trend:
    geo_type: str
    geo_value: str
    signal_source: str
    signal_signal: str

    value: Optional[float] = None

    basis_date: Optional[int] = None
    basis_value: Optional[float] = None
    basis_trend: TrendEnum = TrendEnum.unknown

    min_date: Optional[int] = None
    min_value: Optional[float] = None
    min_trend: TrendEnum = TrendEnum.unknown

    max_date: Optional[int] = None
    max_value: Optional[float] = None
    max_trend: TrendEnum = TrendEnum.unknown

    def asdict(self):
        return asdict(self)


def compute_trend(geo_type: str, geo_value: str, signal_source: str, signal_signal: str, current_time: int, basis_time: int, rows: Iterable[Tuple[int, float]]) -> Trend:
    t = Trend(geo_type, geo_value, signal_source, signal_signal, basis_date=basis_time)

    min_row: Optional[Tuple[int, float]] = None
    max_row: Optional[Tuple[int, float]] = None
    basis_row: Optional[Tuple[int, float]] = None

    # find all needed rows
    for time, value in rows:
        if time == current_time:
            t.value = value
        if time == basis_time:
            t.basis_value = value
        if t.min_value is None or t.min_value > value:
            t.min_date = time
            t.min_value = value
        if t.max_value is None or t.max_value < value:
            t.max_date = time
            t.max_value = value

    if t.value is None or t.min_value is None:
        # cannot compute trend if current time is not found
        return t

    t.basis_trend = compute_trend_value(t.value, t.basis_value, t.min_value) if t.basis_value else TrendEnum.unknown
    t.min_trend = compute_trend_value(t.value, t.min_value, t.min_value)
    t.max_trend = compute_trend_value(t.value, t.max_value, t.min_value) if t.max_value else TrendEnum.unknown

    return t


def compute_trend_value(current: float, basis: float, min_value: float) -> TrendEnum:
    # based on www-covidcast
    normalized_basis = basis - min_value
    normalized_current = current - min_value
    if normalized_basis == normalized_current:
        return TrendEnum.steady
    if normalized_basis == 0:
        return TrendEnum.increasing
    normalized_change = normalized_current / normalized_basis - 1
    if normalized_change >= 0.1:
        return TrendEnum.increasing
    if normalized_change <= -0.1:
        return TrendEnum.decreasing
    return TrendEnum.steady
