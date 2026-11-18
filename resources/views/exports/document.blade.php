<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Saved record copy</title>
<style>
@page { size: A4; margin: 15mm; }
body { font-family: Arial, sans-serif; color: #111; margin: 2rem auto; max-width: 65rem; padding: 0 1rem; }
h1 { font-size: 1.4rem; } p { font-size: .85rem; }
table { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: .8rem; }
th, td { padding: .4rem; text-align: left; vertical-align: top; border: 1px solid #aaa; white-space: pre-wrap; overflow-wrap: anywhere; }
th:first-child, td:first-child { width: 42%; } thead { display: table-header-group; }
@media print { body { margin: 0; padding: 0; max-width: none; } tr { break-inside: avoid; } }
</style>
</head>
<body>
<h1>Saved record copy</h1>
<p>Generated {{ $generatedAt }} (UTC). Times within the record carry their saved offsets. Use the browser print function to print or save as PDF.</p>
<p>This copy records saved information and electronic attribution. Official hospital letterhead and certificate templates require hospital approval.</p>
<table><thead><tr><th>Field</th><th>Saved value</th></tr></thead><tbody>
@foreach ($rows as $row)
<tr><td>{{ $row[0] }}</td><td>{{ $row[1] }}</td></tr>
@endforeach
</tbody></table>
</body>
</html>
