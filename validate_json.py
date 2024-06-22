import sys
import json
from jsonschema import validate

def validate_json(schema_file, json_data):
    with open(schema_file, 'r') as f:
        schema = json.load(f)

    try:
        validate(instance=json_data, schema=schema)
        print("Valid JSON data according to the schema.")
    except Exception as e:
        print(f"Invalid JSON data: {e}")
        sys.exit(1)

if __name__ == '__main__':
    if len(sys.argv) != 3:
        print("Usage: python validate_json.py <schema_file> <json_data_file>")
        sys.exit(1)

    schema_file = sys.argv[1]
    json_data_file = sys.argv[2]

    with open(json_data_file, 'r') as f:
        json_data = json.load(f)

    validate_json(schema_file, json_data)
