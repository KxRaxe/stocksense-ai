"""Settings: the training thread cap, which keeps a run quick on a busy machine."""

from __future__ import annotations

import os

import numpy as np
import pandas as pd

from app.config import Settings
from app.model import train


def test_training_uses_a_modest_number_of_threads_by_default():
    threads = Settings(threads=0).training_threads

    assert 1 <= threads <= 4
    assert threads <= (os.cpu_count() or 1)


def test_the_number_of_threads_can_be_set():
    assert Settings(threads=2).training_threads == 2
    assert Settings(threads=16).training_threads == 16


def test_a_negative_number_means_the_default():
    assert Settings(threads=-1).training_threads == Settings(threads=0).training_threads


def test_it_is_read_from_the_environment(monkeypatch):
    monkeypatch.setenv("ML_THREADS", "3")

    assert Settings().training_threads == 3


def test_the_model_trains_with_that_many_threads():
    rng = np.random.default_rng(0)
    X = pd.DataFrame(rng.uniform(size=(300, 4)), columns=list("abcd"))
    y = rng.uniform(size=300)

    assert train(X, y).regressor.get_params()["n_jobs"] == Settings().training_threads
