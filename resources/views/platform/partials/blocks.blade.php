@foreach ($content['blocks'] as $i => $block)
    @include('platform.sections.'.$block->type, ['block' => $block, 'first' => $i === 0 && ($firstIsTitle ?? true)])
@endforeach
