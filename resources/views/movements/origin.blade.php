<span class="inline-flex rounded-md px-2 py-1 text-xs font-medium {{ $movement->type === 'in' ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800' }}">{{ $movement->type === 'in' ? 'Bevételezés' : 'Kiadás' }}</span>
<span class="block text-xs text-gray-600">{{ $movement->originLabel() }}</span>
