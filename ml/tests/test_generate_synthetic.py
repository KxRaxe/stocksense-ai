"""Tests for the synthetic data generator (scripts/generate_synthetic.py).

The demo dataset is only useful if it is repeatable and realistic, so these
check both: the same seed gives the same files, stock never goes below zero,
and each category shows the pattern it is meant to (paydays, back-to-school,
dry season, products with a short history).
"""

from __future__ import annotations

from pathlib import Path

import pandas as pd
import pytest

from scripts.generate_synthetic import END, PRODUCTS, START, generate, write


@pytest.fixture(scope="module")
def data():
    return generate()


@pytest.fixture(scope="module")
def sales(data) -> pd.DataFrame:
    frame = data.sales.merge(data.products[["sku", "category"]], on="sku")
    frame["date"] = pd.to_datetime(frame["date"])
    frame["quantity"] = frame["quantity"].astype(int)
    return frame


def monthly_units(sales: pd.DataFrame, category: str) -> pd.Series:
    subset = sales[sales["category"] == category]
    return subset.groupby(subset["date"].dt.to_period("M"))["quantity"].sum()


class TestShape:
    def test_has_five_categories_and_fifty_products(self, data):
        assert len(data.categories) == 5
        assert len(data.products) == 50
        assert data.products["category"].value_counts().eq(10).all()

    def test_skus_are_unique(self, data):
        assert data.products["sku"].is_unique

    def test_every_product_category_exists(self, data):
        assert set(data.products["category"]) == set(data.categories["name"])

    def test_covers_twenty_four_months(self, sales):
        assert sales["date"].min() <= pd.Timestamp(START) + pd.Timedelta(days=7)
        assert sales["date"].max() >= pd.Timestamp(END) - pd.Timedelta(days=7)
        assert sales["date"].dt.to_period("M").nunique() == 24

    def test_quantities_are_positive_whole_numbers(self, data):
        assert (data.sales["quantity"] > 0).all()
        assert (data.restocks["quantity"] > 0).all()

    def test_rows_are_sorted_by_date(self, data):
        assert data.sales["date"].is_monotonic_increasing

    def test_sales_only_use_known_skus(self, data):
        known = set(data.products["sku"])
        assert set(data.sales["sku"]) <= known
        assert set(data.restocks["sku"]) <= known


class TestRepeatability:
    def test_same_seed_gives_identical_data(self, data):
        again = generate()

        pd.testing.assert_frame_equal(data.sales, again.sales)
        pd.testing.assert_frame_equal(data.restocks, again.restocks)
        pd.testing.assert_frame_equal(data.products, again.products)

    def test_different_seed_gives_different_sales(self, data):
        other = generate(seed=7)

        assert not data.sales.equals(other.sales)

    def test_written_files_are_identical_between_runs(self, data, tmp_path: Path):
        write(data, tmp_path / "a")
        write(generate(), tmp_path / "b")

        for name in ["categories", "products", "restocks", "sales", "sales-sample-with-errors"]:
            first = (tmp_path / "a" / f"{name}.csv").read_bytes()
            second = (tmp_path / "b" / f"{name}.csv").read_bytes()
            assert first == second, name

    def test_files_use_unix_line_endings(self, data, tmp_path: Path):
        write(data, tmp_path)

        assert b"\r\n" not in (tmp_path / "sales.csv").read_bytes()

    def test_committed_demo_files_match_the_generator(self, data, tmp_path: Path):
        """The CSVs in web/database/data must be what the generator produces, so
        they cannot drift. Run the generator again if this fails."""
        repo_data = Path(__file__).resolve().parents[2] / "web" / "database" / "data"
        candidates = [repo_data, Path("/data")]
        committed = next((c for c in candidates if (c / "sales.csv").is_file()), None)

        if committed is None:
            pytest.skip("the demo data folder is not available here")

        write(data, tmp_path)

        for name in ["categories", "products", "restocks", "sales", "sales-sample-with-errors"]:
            expected = (tmp_path / f"{name}.csv").read_bytes()
            assert (committed / f"{name}.csv").read_bytes() == expected, (
                f"{name}.csv is out of date; run scripts/generate_synthetic.py"
            )


class TestFileFormat:
    def test_columns(self, data, tmp_path: Path):
        write(data, tmp_path)

        def header(name: str) -> list[str]:
            return (tmp_path / f"{name}.csv").read_text().splitlines()[0].split(",")

        assert header("categories") == ["name", "description", "service_level"]
        assert header("sales") == ["date", "sku", "quantity", "unit_price"]
        assert header("restocks") == ["date", "sku", "quantity"]
        assert header("products") == [
            "sku", "name", "category", "unit", "unit_cost", "unit_price", "lead_time_days",
            "moq", "pack_size", "reorder_point", "opening_stock", "start_date",
        ]  # fmt: skip

    def test_prices_have_two_decimals(self, data):
        assert data.sales["unit_price"].str.fullmatch(r"\d+\.\d{2}").all()
        assert data.products["unit_price"].str.fullmatch(r"\d+\.\d{2}").all()

    def test_dates_are_iso(self, data):
        assert data.sales["date"].astype(str).str.fullmatch(r"\d{4}-\d{2}-\d{2}").all()

    def test_only_the_reorder_point_may_be_blank(self, data):
        blanks = data.products.replace("", pd.NA).isna().sum()

        assert blanks.drop("reorder_point").sum() == 0
        # Half the catalog has one, as a new user's would.
        assert 20 <= data.products["reorder_point"].ne("").sum() <= 30


class TestStockSimulation:
    def test_stock_never_goes_below_zero(self, data):
        """Replaying opening stock, deliveries and sales day by day never dips under zero."""
        columns = ["sku", "date", "change"]
        products = data.products
        opening = products.assign(date=products["start_date"], change=products["opening_stock"])
        delivered = data.restocks.assign(change=data.restocks["quantity"])
        sold = data.sales.assign(change=-data.sales["quantity"].astype(int))

        events = pd.concat([opening[columns], delivered[columns], sold[columns]])
        events["change"] = events["change"].astype(int)

        # Within a day, deliveries and opening stock come before sales.
        events["order"] = (events["change"] < 0).astype(int)
        events = events.sort_values(["sku", "date", "order"])
        events["balance"] = events.groupby("sku")["change"].cumsum()

        assert (events["balance"] >= 0).all()

    def test_has_occasional_stockouts_but_not_many(self, data):
        share = data.stockout_days / data.open_days

        assert data.stockout_days > 0
        assert share < 0.08

    def test_restocks_follow_each_products_pack_size(self, data):
        packs = data.products.set_index("sku")["pack_size"]
        restocks = data.restocks.assign(pack=data.restocks["sku"].map(packs))

        assert (restocks["quantity"] % restocks["pack"] == 0).all()

    def test_no_delivery_before_a_product_starts_selling(self, data):
        start = data.products.set_index("sku")["start_date"]
        restocks = data.restocks.assign(start=data.restocks["sku"].map(start))

        assert (restocks["date"] >= restocks["start"]).all()


class TestHistoryLength:
    def test_some_products_have_under_a_year_of_history(self, sales):
        """The model must cope with new products, so the data has a few."""
        first = sales.groupby("sku")["date"].min()
        months = (pd.Timestamp(END) - first).dt.days / 30.4

        assert 3 <= (months < 12).sum() <= 8

    def test_new_products_start_when_they_are_launched(self, sales):
        launches = {spec.sku: spec.start for spec in PRODUCTS if spec.start > START}
        first = sales.groupby("sku")["date"].min()

        for sku, launch in launches.items():
            assert first[sku] >= pd.Timestamp(launch)
            # ...and within a couple of weeks of it (slow movers may take a few days).
            assert first[sku] <= pd.Timestamp(launch) + pd.Timedelta(days=14)


class TestSeasonality:
    def test_school_supplies_peak_before_the_school_year(self, sales):
        monthly = monthly_units(sales, "School and office supplies")
        average = monthly.mean()
        june = monthly[monthly.index.month == 6]
        october = monthly[monthly.index.month == 10]

        assert (june > 1.4 * average).all()
        assert (october < 0.9 * average).all()

    def test_food_is_busiest_in_december(self, sales):
        monthly = monthly_units(sales, "Food and beverages")
        december = monthly[monthly.index.month == 12]

        assert (december > 1.1 * monthly.mean()).all()

    def test_hardware_is_busier_in_the_dry_season(self, sales):
        monthly = monthly_units(sales, "Hardware and construction")
        dry = monthly[monthly.index.month.isin([3, 4, 5])].mean()
        wet = monthly[monthly.index.month.isin([7, 8, 9])].mean()

        assert dry > 1.3 * wet

    def test_food_sells_more_around_payday(self, sales):
        food = sales[sales["category"] == "Food and beverages"]
        daily = food.groupby("date")["quantity"].sum()
        near_payday = daily.index.day.isin([14, 15, 16, 30, 31, 1])
        far = ~daily.index.day.isin([14, 15, 16, 30, 31, 1, 2, 29])

        assert daily[near_payday].mean() > 1.15 * daily[far].mean()

    def test_busiest_weekday_for_food_is_saturday(self, sales):
        food = sales[sales["category"] == "Food and beverages"]
        by_weekday = food.groupby(food["date"].dt.dayofweek)["quantity"].sum()

        assert by_weekday.idxmax() == 5

    def test_school_supplies_sell_less_at_the_weekend(self, sales):
        school = sales[sales["category"] == "School and office supplies"]
        by_weekday = school.groupby(school["date"].dt.dayofweek)["quantity"].sum()

        assert by_weekday[6] < by_weekday[[0, 1, 2, 3, 4]].mean()

    def test_hardware_demand_is_intermittent(self, sales, data):
        """Slow movers have plenty of days with no sales at all."""
        slow = data.products[data.products["sku"].isin(["HW-007", "HW-008"])]["sku"]
        rows = sales[sales["sku"].isin(slow)].groupby("sku").size()

        # Fewer than half the days have a sale.
        assert (rows < 0.5 * 730).all()

    def test_nothing_sells_on_closed_days(self, sales):
        christmas = (sales["date"].dt.month == 12) & (sales["date"].dt.day == 25)
        new_year = (sales["date"].dt.month == 1) & (sales["date"].dt.day == 1)

        assert not (christmas | new_year).any()

    def test_prices_rise_when_a_price_change_takes_effect(self, sales):
        nails = sales[sales["sku"] == "HW-001"]
        before = nails[nails["date"] < "2025-07-01"]["unit_price"].astype(float).unique()
        after = nails[nails["date"] >= "2025-07-01"]["unit_price"].astype(float).unique()

        assert len(before) == 1 and len(after) == 1
        assert after[0] == pytest.approx(before[0] * 1.05, abs=0.01)


class TestErrorSample:
    def test_sample_file_has_a_few_deliberate_mistakes(self, data, tmp_path: Path):
        write(data, tmp_path)
        sample = pd.read_csv(tmp_path / "sales-sample-with-errors.csv", dtype=str)
        known = set(data.products["sku"])

        assert len(sample) == 40
        assert (~sample["sku"].isin(known)).sum() == 1
        assert sample["quantity"].isin(["many", "2.5"]).sum() == 2
        assert sample["date"].isin(["2026-13-45", "2999-01-01"]).sum() == 2
        assert (sample["unit_price"] == "cheap").sum() == 1
