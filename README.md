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

**Conventions**  
- Comments in code are in English.
- camelCase naming in code.
