<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Termékek Listája</title>
</head>
<body style="font-family: Arial, sans-serif; padding: 20px;">

    <h1>Raktárkészlet - Termékek</h1>

    <table border="1" cellpadding="10" cellspacing="0" style="width: 100%; text-align: left;">
        <thead style="background-color: #f4f4f4;">
            <tr>
                <th>Cikkszám (SKU)</th>
                <th>Terméknév</th>
                <th>Kategória</th>
                <th>Ár</th>
            </tr>
        </thead>
        <tbody>
            @foreach($products as $product)
            <tr>
                <td>{{ $product->sku }}</td>
                <td>{{ $product->product_name }}</td>
                <td>{{ $product->category->category_name }}</td>
                <td>{{ $product->price }} Ft</td>
            </tr>
            @endforeach
        </tbody>
    </table>

</body>
</html>