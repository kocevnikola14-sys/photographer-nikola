#!/usr/bin/env python3
"""
Zaženi ta skript VSAKIČ ko dodaš ali odstraniš fotografije iz mape photos/.
Samodejno posodobi photos/manifest.json.

Uporaba:
    python3 generate-manifest.py
"""

import os
import json

PHOTOS_DIR = os.path.join(os.path.dirname(__file__), 'photos')
EXTENSIONS = {'.jpg', '.jpeg', '.png', '.webp', '.gif'}

files = sorted([
    f for f in os.listdir(PHOTOS_DIR)
    if os.path.splitext(f)[1].lower() in EXTENSIONS
])

manifest_path = os.path.join(PHOTOS_DIR, 'manifest.json')
with open(manifest_path, 'w', encoding='utf-8') as out:
    json.dump(files, out, ensure_ascii=False, indent=2)

print(f"✓ manifest.json posodobljen — {len(files)} fotografij:")
for f in files:
    print(f"  · {f}")
