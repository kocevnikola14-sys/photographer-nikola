#!/usr/bin/env python3
import os
import json
import sys

# Mape s fotografijami
CATEGORIES = ['koncerti', 'portreti', 'mesana zbirka']
EXTENSIONS = {'.jpg', '.jpeg', '.png', '.webp', '.gif'}

print("🔍 Pregledujem mape...")
print(f"📁 Mape: {CATEGORIES}")
print(f"📄 Končnice: {EXTENSIONS}")
print("-" * 40)

for category in CATEGORIES:
    folder_path = os.path.join(os.path.dirname(__file__), category)
    
    print(f"\n📂 Pregledujem: {category}")
    print(f"   Pot: {folder_path}")
    
    # Preveri ali mapa obstaja
    if not os.path.exists(folder_path):
        print(f"   ⚠️ Mapa ne obstaja!")
        continue
    
    # Poglej vse datoteke v mapi
    all_files = os.listdir(folder_path)
    print(f"   📄 Vse datoteke v mapi: {all_files}")
    
    # Zberi samo slike
    files = sorted([
        f for f in all_files
        if os.path.splitext(f)[1].lower() in EXTENSIONS 
        and f != 'manifest.json'
    ])
    
    print(f"   🖼️ Najdene slike: {files}")
    
    # Zapiši manifest.json
    manifest_path = os.path.join(folder_path, 'manifest.json')
    with open(manifest_path, 'w', encoding='utf-8') as out:
        json.dump(files, out, ensure_ascii=False, indent=2)
    
    print(f"   ✅ Zapisano v {manifest_path}")
    print(f"   📊 Število slik: {len(files)}")

print("\n" + "=" * 40)
print("🎉 Vsi manifesti posodobljeni!")
