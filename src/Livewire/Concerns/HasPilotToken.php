<?php

namespace TheFountainhead\Metis\Livewire\Concerns;

/**
 * Pilot-adgang: brugeren har et registry-api-token hæftet på sessionen
 * (EmailGate::verifyCode hæfter det for e-mails i metis.gating.pilot_users;
 * AlertsInbox lader en pilot indtaste det manuelt).
 *
 * Er gaten slået fra (embedded mode bag værtens egen login), findes der ingen
 * e-mail-gate at sende brugeren til, og adgangen er værtens ansvar.
 *
 * ÉN kilde til prædikatet, så de sider der bruger den ikke drifter fra
 * hinanden: /soeg, /engagementer og /engagementer/{key}.
 */
trait HasPilotToken
{
    public function hasUserToken(): bool
    {
        if (! config('metis.gating.enabled', true)) {
            return true;
        }

        return ! empty(session('metis_user_token'));
    }
}
