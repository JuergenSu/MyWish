"""Inspect disposable private JSON stores from HTTP fixtures."""
import json,pathlib

def data_directory(storage):
 return pathlib.Path(json.loads((pathlib.Path(storage)/'layout.php').read_text().split('\n',1)[1])['directory'])

def disk_state(storage):
 root=data_directory(storage);manifest=json.loads((root/'manifest.json').read_text())
 return {'schemaVersion':manifest['schemaVersion'],**{table:json.loads((root/'records'/file).read_text()) for table,file in manifest['collections'].items()}}
