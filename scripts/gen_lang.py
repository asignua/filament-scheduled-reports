#!/usr/bin/env python3
"""Writes resources/lang/<locale>/filament-scheduled-reports.php from scripts/_en.py and scripts/_tr.py."""
import os, sys
sys.path.insert(0, os.path.dirname(__file__))
from _en import EN
from _tr import T

root = os.path.join(os.path.dirname(__file__), '..', 'resources', 'lang')

def php(s):
    return "'" + s.replace('\\', '\\\\').replace("'", "\\'") + "'"

def write(locale, values):
    lines = ["<?php", "", "declare(strict_types=1);", "", "return [", ""]
    for key, value in zip(EN.keys(), values):
        lines.append(f"    {php(key)} => {php(value)},")
    lines.append("];")
    os.makedirs(os.path.join(root, locale), exist_ok=True)
    open(os.path.join(root, locale, 'filament-scheduled-reports.php'), 'w').write("\n".join(lines) + "\n")

write('en', list(EN.values()))
for locale, values in T.items():
    assert len(values) == len(EN), (locale, len(values), len(EN))
    write(locale, values)
print('ok', len(EN))
