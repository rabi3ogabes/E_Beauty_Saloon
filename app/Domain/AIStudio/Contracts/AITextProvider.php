<?php

namespace App\Domain\AIStudio\Contracts;

/** Text / vision models (Claude, Gemini, OpenAI) — analysis, recommendations, prompts and captions. */
interface AITextProvider
{
    /** @return array{face_shape:?string, skin_tone:?string, undertone:?string, hair_texture:?string, notes:?string} */
    public function analyzeFace(string $image, string $mime, string $locale): array;

    /**
     * @param  list<array{id:int, name:string, category:string}>  $styles
     * @return list<array{style_id:int, reason:string}>
     */
    public function recommend(array $analysis, array $styles, string $locale): array;

    /** Turns a style + client context into a precise image-edit prompt. */
    public function writePrompt(array $context): string;

    /** Short marketing caption / copy in the given locale. */
    public function writeCaption(string $brief, string $tone, string $locale): string;
}
