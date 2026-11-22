@php($stockRow = $stockRow ?? 'location')
@foreach($stockScopes as $scope)
    <p x-show="{{ $stockRow }}?.product_stock?.{{ $scope }}" data-putaway-stock="{{ $scope }}" class="min-w-0 text-sm text-gray-700">
        @switch($scope)
            @case('bin') <span>Ebből a termékből a rekeszben:</span> @break
            @case('level') <span>Ezen a polcszinten:</span> @break
            @case('system')
                <span x-text="{{ $stockRow }}?.product_stock?.system?.kind === 'floor' ? 'Ebből a termékből a tárolóterületen:' : 'Ebből a termékből az állványon:'"></span>
                @break
        @endswitch
        <strong x-text="stockCaption({{ $stockRow }}?.product_stock?.{{ $scope }})"></strong>
    </p>
@endforeach
