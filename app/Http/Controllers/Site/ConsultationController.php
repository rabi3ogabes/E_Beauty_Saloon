<?php

namespace App\Http\Controllers\Site;

use App\Domain\Insights\Actions\SignConsultationForm;
use App\Domain\Insights\Models\ConsultationForm;
use App\Http\Controllers\Controller;
use App\Support\Exceptions\DomainException;
use Illuminate\Http\Request;

/** Public consultation form (by unguessable token) with an e-signature pad. */
class ConsultationController extends Controller
{
    public function show(string $token)
    {
        $form = ConsultationForm::with('client')->where('token', $token)->firstOrFail();
        app()->setLocale(request()->query('lang') && in_array(request()->query('lang'), ['ar', 'en'], true) ? request()->query('lang') : $form->locale);

        return view('site.insights.consultation', ['form' => $form]);
    }

    public function sign(Request $request, string $token, SignConsultationForm $sign)
    {
        $form = ConsultationForm::with('client')->where('token', $token)->firstOrFail();
        app()->setLocale($form->locale);
        $data = $request->validate([
            'signed_name' => 'required|string|max:120',
            'signature' => 'required|string|max:800000',
            'answers' => 'array',
            'answers.patch_test' => 'nullable|in:yes,no,na',
            'answers.patch_test_date' => 'nullable|date',
            'answers.allergies' => 'nullable|string|max:1000',
            'answers.medications' => 'nullable|string|max:1000',
            'answers.pregnant' => 'nullable|boolean',
            'answers.skin_conditions' => 'nullable|string|max:1000',
            'answers.notes' => 'nullable|string|max:1000',
            'answers.consent' => 'accepted',
        ]);

        try {
            $sign->handle($form, $data['answers'] ?? [], $data['signature'], $data['signed_name'], $request->ip());
        } catch (DomainException $e) {
            return back()->withInput($request->except('signature'))->withErrors(['signature' => $e->getMessage()]);
        }

        return redirect('/consultation/'.$form->token)->with('status', __('insights.consultation.thanks'));
    }
}
