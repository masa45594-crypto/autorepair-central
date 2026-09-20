"""Write a privacy-safe status summary that travels with the GitHub Actions artifact."""
import json, os
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
jobs={name:os.environ.get(name.upper()+'_RESULT','unknown') for name in ('central','unit','restore','stripe','load')}
out={'schema':1,'source':'github_actions','jobs':jobs,'note':'Stripe and load are intentionally optional workflow-dispatch jobs.'}
(ROOT/'reports').mkdir(exist_ok=True)
(ROOT/'reports'/'verification-summary.json').write_text(json.dumps(out,indent=2)+'\n')
print(json.dumps(out))
