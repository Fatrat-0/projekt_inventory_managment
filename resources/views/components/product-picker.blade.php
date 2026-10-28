@props(['products', 'id', 'value' => '', 'form' => null, 'required' => false])
@php
    $items = $products->map(fn($product) => ['value' => (string)$product->product_id, 'label' => $product->product_name.' ('.$product->sku.')'])->values();
@endphp
<x-autocomplete-picker :items="$items" :id="$id" name="product_id" :value="$value" :form="$form" :required="$required" placeholder="Terméknév vagy SKU…" event-name="product-selected" />
