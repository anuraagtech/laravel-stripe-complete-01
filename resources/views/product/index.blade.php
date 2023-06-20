<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Product Page</title>
</head>

<body>
  <div style="display: flex; gap: 2rem">
    @foreach ($products as $product)
      <div class="flex: 1">
        <img src="{{ $product->image }}" alt="product image" style="max-width: 100%">
        <h2>{{ $product->name }}</h2>
        <p>Rs. {{ $product->price }}</p>
      </div>
    @endforeach
  </div>

  <form action="/checkout" method="post">
    @csrf
    <button type="submit">Checkout</button>
  </form>




</body>

</html>
