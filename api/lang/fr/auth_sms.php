<?php

declare(strict_types=1);

// SMS texts — keep within 160 GSM-7 characters (accents like "é" are GSM-7; avoid others).
return [
    'otp' => 'Yekkola : votre code est :code. Il expire dans :minutes minutes. Ne le partagez avec personne.',
    'otp_payout' => 'Yekkola : code :code pour CHANGER le numero qui recoit vos paiements. Expire dans :minutes min. Si ce n\'est pas vous, ne le donnez a personne.',
    'phone_changed' => 'Yekkola : le numero de votre compte a ete change vers :phone. Si ce n\'est pas vous, contactez le support.',
];
