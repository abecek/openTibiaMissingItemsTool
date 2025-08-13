# MapMissingItems

PHP 8.3 + Symfony Console tool that finds items used on an OTBM map but **missing** from `items.xml` (TFS 1.5, 8.6).
It integrates with the NodeJS library **OTBM2JSON** to read `.otbm` and produces a CSV/XLSX report.

## Quick start

```bash
composer install
```

```bash
php bin/console map:gaps:scan \
  --items-xml data/items.xml \
  --map data/input/world.otbm \
  --format xlsx \
  --output data/output/missing-items.xlsx
```

E.g.
```bash
php bin/console map:gaps:scan \
  --map="C:\otsDev\TFS-1.5-Downgrades-8.60-upgrade\data\world\test3-860.otbm" \
  --format xlsx 
```

Defaults:
- `--map` → `data/input/world.otbm`
- `--output` → `data/output/missing-items.xlsx`

The program will automatically:
1. Check for NodeJS in PATH.
2. If missing locally, clone `OTBM2JSON` into `tools/otbm2json/` (git clone).
3. Generate the Node wrapper `tools/otbm2json/run.js` and run the OTBM→JSON conversion.

### Report columns
- id
- occurrences
- example_positions
- article (empty)
- name (empty)
- weight_attr (empty)
- description_attr (empty)
- slotType_attr (empty)
- weaponType_attr (empty)
- armor_attr (empty)
- defense_attr (empty)

### Windows: installing Node.js & Git
1. Install Node.js (LTS): https://nodejs.org/  
   Verify: `node -v`
2. Install Git for Windows: https://git-scm.com/download/win  
   Verify: `git --version`

### Logging and sampling
- File logging via Monolog. Default log path: `logs/app.log` (override with `--log-file`).
- Fast dry run via `--sample=N` to limit processed map item records.

Examples:
```bash
php bin/console map:gaps:scan --items-xml data/items.xml --sample=50000 --log-file logs/scan.log
```

```bash
php bin/console map:gaps:scan --items-xml data/items.xml --format csv
```

```bash
php bin/console map:gaps:scan \
  --map="C:\otsDev\TFS-1.5-Downgrades-8.60-upgrade\data\world\test3-860.otbm" \
  --format xlsx \
  --node-max-old-space 3072
```

```bash
php bin/console map:gaps:scan \
  --items-xml="data/input/items_original.xml" \
  --map="C:\otsDev\TFS-1.5-Downgrades-8.60-upgrade\data\world\test3-860.otbm" \
  --output="data/output/missing-items-original.xlsx" \
  --format xlsx \
  --node-max-old-space 3072
```

By default sort results by occurrences column

```bash
php bin/console map:gaps:scan \
  --items-xml="data/input/items.xml" \
  --map="C:\otsDev\TFS-1.5-Downgrades-8.60-upgrade\data\world\test3-860.otbm" \
  --output="data/output/missing-items.xlsx" \
  --images-dir="C:\otsDev\clientResourcesModifications\8.6 spr i dat WLASNY - new test2\rawItemImages\items" \
  --format xlsx \
  --node-max-old-space 3072
```

Sorting by id - ascending

```bash
php bin/console map:gaps:scan \
  --items-xml="data/input/items.xml" \
  --map="C:\otsDev\TFS-1.5-Downgrades-8.60-upgrade\data\world\test3-860.otbm" \
  --output="data/output/missing-items.xlsx" \
  --images-dir="C:\otsDev\clientResourcesModifications\8.6 spr i dat WLASNY - new test2\rawItemImages\items" \
  --format xlsx \
  --sort id-asc \
  --node-max-old-space 3072
```

# Augment items.xml from a filled report

After you run `map:gaps:scan` and manually fill **article** and **name** columns in the generated report (CSV/XLSX),
you can append those items back into `items.xml`:

```bash
php bin/console items:xml:augment \
  --items-xml data/input/items.xml \
  --report data/output/missing-items.xlsx
```

## What it does
Reads the report (XLSX/CSV) and takes only rows where: 
id is a positive integer name is non-empty (article optional)
Skips any id already present in items.xml, including those covered by existing ranges fromid–toid.
Sorts remaining IDs in ascending order and merges consecutive IDs with identical (article, name) into a single range:

```xml
<item fromid="1001" toid="1004" article="a" name="marble flooring"/>
```

Appends a clearly marked block at the end of items.xml without changing the order of existing entries.
Creates a timestamped backup by default (e.g. items.xml.bak.20250814_203355).

## Options
- `--items-xml` – path to items.xml (default: data/input/items.xml)
- `--report `– path to report (.xlsx or .csv). If omitted, the tool picks the first existing:
`data/output/missing-items.xlsx`, or `data/output/missing-items.csv`
- `--csv-delimiter` – CSV delimiter when reading .csv reports (default: ,)
- `--no-backup` – disable backup file creation
- `--sheet` – 0-based sheet index for XLSX (default: 0)
- `--row-chunk` – progress granularity while scanning the report (default: 5000)
- `--dry-run` – preview the items that would be appended, without writing to items.xml

### Basic (XLSX)
```bash
php bin/console items:xml:augment \
--items-xml="data/input/items.xml" \
--report="data/output/missing-items.xlsx"
```

### Dry run (no changes written)
```bash
php bin/console items:xml:augment \
--items-xml="data/input/items.xml" \
--report="data/output/missing-items.xlsx" \
--dry-run
```

### CSV with semicolon delimiter
```bash
php bin/console items:xml:augment \
--items-xml="data/input/items.xml" \
--report="data/output/missing-items.csv" \
--csv-delimiter=";"
```

