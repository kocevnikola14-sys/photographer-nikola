#!/usr/bin/env python3
import os
import json

# Mape s fotografijami - preveri točna imena!
CATEGORIES = ['koncerti', 'portreti', 'mesana zbirka']
EXTENSIONS = {'.jpg', '.jpeg', '.png', '.webp', '.gif'}

print("🔍 Pregledujem mape...")

for category in CATEGORIES:
    folder_path = os.path.join(os.path.dirname(__file__), category)
    
    # Preveri ali mapa obstaja
    if not os.path.exists(folder_path):
        print(f"⚠️ Mapa '{category}' ne obstaja - preskočeno")
        continue
    
    # Zberi vse slike (izključi manifest.json)
    files = sorted([
        f for f in os.listdir(folder_path)
        if os.path.splitext(f)[1].lower() in EXTENSIONS 
        and f != 'manifest.json'
    ])
    
    # Zapiši manifest.json
    manifest_path = os.path.join(folder_path, 'manifest.json')
    with open(manifest_path, 'w', encoding='utf-8') as out:
        json.dump(files, out, ensure_ascii=False, indent=2)
    
    print(f"✅ {category}/manifest.json — {len(files)} fotografij")

print("🎉 Vsi manifesti posodobljeni!")
