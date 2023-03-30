import re
import time

PAYLOAD_GUID_PHRASE = "§CAKEFUZZER_PAYLOAD_GUID§"
simple_payloads = [
    f'<script>console.log("__CAKEFUZZER_XSS_{PAYLOAD_GUID_PHRASE}__")</script>',
    f"<script>console.log('__CAKEFUZZER_XSS_{PAYLOAD_GUID_PHRASE}__')</script>",
    f"<script>alert`__CAKEFUZZER_XSS_{PAYLOAD_GUID_PHRASE}__`</script>",
]

phrases = [
    "[^\n]{0,40}" + re.escape(payload) + "[^\n]{0,40}" for payload in simple_payloads
]

# phrase = phrases[0]

files = [
    "0e015154-b861-4d14-90b4-b0b382922e39",
    "1cfac1ce-b496-49bc-8d3e-c06cd9e4ea90",
    "1f7713b5-6d1b-4037-9ba7-2379e2c5403a",
    "4e23169b-350f-4916-9768-f8042f75af48",
    "522fa352-e2b8-4c1d-be9d-fd22fea413f1",
    "dd1cf72a-2d62-44bb-a86b-600a95da1b5b",
    "e992ed58-0cde-4d3c-8230-3ab0d7f8a3f6",
]
total_total = 0
for file in files:
    total = 0
    for phrase in phrases:
        with open(f"longs/{file}") as f:
            string = f.read()

            start = time.time()

            parts = phrase.split(PAYLOAD_GUID_PHRASE)

            regex = f"({parts[0]})(?P<CAKEFUZZER_PAYLOAD_GUID>[0-9]+)({parts[1]})"

            machine = re.compile(regex, flags=re.DOTALL)

            results = list(machine.finditer(string))

            elapsed = time.time() - start

            total += elapsed

            print(f"    Elapsed: {elapsed:.4f}")

    print(f"Total: {total}")
    total_total += total

print(f"----------------\nTotal total: {total_total}")
