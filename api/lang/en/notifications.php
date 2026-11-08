<?php

declare(strict_types=1);

// Notification texts (PRD-10). `sms` lines: 160 GSM-7 characters at most.
return [
    'open' => 'Open',

    'payment_succeeded' => [
        'receipt' => ['title' => 'Payment received', 'body' => 'Your payment of :amount (order :number) is confirmed. Your courses are ready.'],
        'gift' => ['title' => 'A course was given to you', 'body' => 'Order :number gives you access to new courses.'],
        'payer' => ['title' => 'Payment received', 'body' => 'Payment of :amount received for order :number.', 'sms' => 'Yekkola: thank you, payment of :amount received for order :number.'],
    ],
    'payment_failed' => [
        'failed' => ['title' => 'Payment not completed', 'body' => 'The payment for order :number didn’t go through. You can try again.'],
        'expired' => ['title' => 'Order expired', 'body' => 'Order :number wasn’t paid in time. You can place a new one.'],
    ],
    'refund_decided' => [
        'approved' => ['title' => 'Refund approved', 'body' => 'Your refund of :amount (order :number) is approved and on its way.'],
        'rejected' => ['title' => 'Refund declined', 'body' => 'Your refund request for order :number was declined.'],
    ],
    'course_updated' => ['title' => 'Course updated', 'body' => '“:course” has a new version.'],
    'question_answered' => ['title' => 'The professor answered', 'body' => 'The professor answered your question “:title”.'],
    'certificate_issued' => ['title' => 'Certificate earned', 'body' => 'Congratulations! Your certificate for “:course” is ready.'],
    'application_decided' => [
        'approved' => ['title' => 'Application approved', 'body' => 'Welcome to Yekkola’s professors! You can create your first course.', 'sms' => 'Yekkola: your professor application is approved. Welcome! Create your first course on Yekkola.'],
        'rejected' => ['title' => 'Application declined', 'body' => 'Your professor application was not accepted. The reasons are in your space.', 'sms' => 'Yekkola: your professor application was not accepted. Details in your space.'],
        'needs_info' => ['title' => 'More information needed', 'body' => 'The team needs more details about your professor application.', 'sms' => 'Yekkola: we need more details about your application. Open your space to reply.'],
    ],
    'version_decided' => [
        'approved' => ['title' => 'Course approved', 'body' => '“:course” is approved and live.'],
        'rejected' => ['title' => 'Course needs changes', 'body' => '“:course” wasn’t approved. The team’s notes are in the studio.'],
    ],
    'new_question' => ['title' => 'New question', 'body' => 'A student asks: “:title”.'],
    'new_review' => ['title' => 'New review', 'body' => 'New review (:rating/5) on “:course”.'],
    'new_sale' => ['title' => 'New sale', 'body' => 'A student bought “:course”.'],
    'payout_sent' => ['title' => 'Payout sent', 'body' => ':amount sent to your number ending in :last_digits.', 'sms' => 'Yekkola: payout of :amount sent to your number ending in :last_digits.'],
    'payout_failed' => ['title' => 'Payout failed', 'body' => 'The payout of :amount to your number ending in :last_digits failed. Check your payout method.', 'sms' => 'Yekkola: the payout of :amount failed. Check your payout number in your professor space.'],
    'payout_held' => ['title' => 'Payout on hold', 'body' => 'Your payout of :amount couldn’t go out: check your identity and payout method in the studio. The money stays in your balance.'],
    'kyc_decided' => [
        'verified' => ['title' => 'Identity verified', 'body' => 'Your identity is verified: your payouts can go out.'],
        'rejected' => ['title' => 'Verification declined', 'body' => 'Your identity check was declined. Please send new documents.'],
    ],
    'account_status' => [
        'suspended' => ['title' => 'Account suspended', 'body' => 'Your account is suspended. Contact support to find out more.', 'sms' => 'Yekkola: your account is suspended. Contact support to find out more.'],
        'banned' => ['title' => 'Account closed', 'body' => 'Your account was closed for breaking the rules.', 'sms' => 'Yekkola: your account was closed for breaking the rules.'],
    ],
    'course_taken_down' => ['title' => 'Course taken down', 'body' => '“:course” was taken down by the Yekkola team. Contact support to find out more.'],
    'warning' => [
        'course' => ['title' => 'Warning from the Yekkola team', 'body' => 'Your course “:title” was reported and breaks the rules. Please fix it.'],
        'review' => ['title' => 'Warning from the Yekkola team', 'body' => 'Your review of “:title” breaks the rules. Please fix it.'],
        'thread' => ['title' => 'Warning from the Yekkola team', 'body' => 'Your question “:title” breaks the rules.'],
        'reply' => ['title' => 'Warning from the Yekkola team', 'body' => 'Your reply in “:title” breaks the rules.'],
        'professor' => ['title' => 'Warning from the Yekkola team', 'body' => 'Your professor profile breaks the rules. Please fix it.'],
    ],
    'staff_alert' => [
        'ledger' => ['title' => 'Ledger: problem found', 'body' => 'The daily check found :count ledger problem(s). Please check the ledger.'],
        'payout_batch' => ['title' => 'Payout batch: failures', 'body' => ':count payout(s) in the batch failed. Check and retry.'],
        'stuck_payments' => ['title' => 'Stuck payments', 'body' => ':count payment(s) have been waiting for the operator for more than 30 minutes.'],
    ],
    'digest' => [
        'subject' => 'Your day on Yekkola',
        'intro' => 'Here is what happened on your courses in the last 24 hours:',
        'new_sale' => '{1} :count new sale|[2,*] :count new sales',
        'new_review' => '{1} :count new review|[2,*] :count new reviews',
        'new_question' => '{1} :count new question|[2,*] :count new questions',
    ],
];
