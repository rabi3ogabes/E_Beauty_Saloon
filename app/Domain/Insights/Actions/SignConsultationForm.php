<?php

namespace App\Domain\Insights\Actions;

use App\Domain\Insights\Models\ConsultationForm;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Client signs the consultation form: answers stored encrypted, the drawn signature saved as a PNG on the PRIVATE
 * (local) disk under consultations/{tenant}/{form}.png, allergies copied onto the client profile for the team.
 */
class SignConsultationForm
{
    public const MAX_SIGNATURE_BYTES = 512 * 1024;

    public function handle(ConsultationForm $form, array $answers, string $signatureDataUrl, string $signedName, ?string $ip = null): ConsultationForm
    {
        if ($form->isSigned()) {
            throw new DomainException(__('insights.consultation.already_signed'));
        }
        if ($form->isExpired()) {
            throw new DomainException(__('insights.consultation.expired'));
        }
        if (empty($answers['consent'])) {
            throw new DomainException(__('insights.consultation.consent_required'));
        }
        $png = $this->decodePng($signatureDataUrl);

        return DB::transaction(function () use ($form, $answers, $png, $signedName, $ip) {
            $path = 'consultations/'.$form->tenant_id.'/'.$form->id.'.png';
            Storage::disk('local')->put($path, $png);

            $clean = collect(ConsultationForm::QUESTIONS)->mapWithKeys(fn ($q) => [$q => is_string($answers[$q] ?? null) ? mb_substr(trim($answers[$q]), 0, 1000) : ($answers[$q] ?? null)])->all();
            $clean['pregnant'] = filter_var($answers['pregnant'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $clean['consent'] = true;

            $form->update([
                'answers' => $clean, 'signature_path' => $path, 'signed_name' => mb_substr(trim($signedName), 0, 120),
                'signed_ip' => $ip, 'signed_at' => now(), 'status' => 'signed',
            ]);

            if (! empty($clean['allergies']) && $form->client) {
                $form->client->forceFill(['allergies' => trim(($form->client->allergies ? $form->client->allergies."\n" : '').$clean['allergies'])])->save();
            }
            activity()->performedOn($form)->withProperties(['ip' => $ip])->log('consultation.signed');

            return $form;
        });
    }

    protected function decodePng(string $dataUrl): string
    {
        if (! preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) {
            throw new DomainException(__('insights.consultation.signature_required'));
        }
        $bin = base64_decode($m[1], true);
        if (! $bin || strlen($bin) > self::MAX_SIGNATURE_BYTES || ! str_starts_with($bin, "\x89PNG\r\n\x1a\n")) {
            throw new DomainException(__('insights.consultation.signature_required'));
        }

        return $bin;
    }
}
