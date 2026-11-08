<?php

declare(strict_types=1);

// Notification texts (PRD-10). `sms` lines: 160 GSM-7 characters at most.
return [
    'open' => 'Open',

    'payment_succeeded' => [
        'receipt' => ['title' => 'Payment received', 'body' => 'Your payment of :amount (order :number) is confirmed. Your courses are ready.', 'sms' => 'Yekkola: payment of :amount received (order :number). Your courses are ready in the app.'],
        'gift' => ['title' => 'A course was given to you', 'body' => 'Order :number gives you access to new courses.', 'sms' => 'Yekkola: a course was given to you (order :number).'],
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
    'kyc_decided' => [
        'verified' => ['title' => 'Identity verified', 'body' => 'Your identity is verified: your payouts can go out.'],
        'rejected' => ['title' => 'Verification declined', 'body' => 'Your identity check was declined. Please send new documents.'],
    ],
    'account_status' => [
        'active' => ['title' => 'Account reactivated', 'body' => 'Your account is active again.', 'sms' => 'Yekkola: your account is active again.'],
        'suspended' => ['title' => 'Account suspended', 'body' => 'Your account is suspended. Contact support to find out more.', 'sms' => 'Yekkola: your account is suspended. Contact support to find out more.'],
        'banned' => ['title' => 'Account closed', 'body' => 'Your account was closed for breaking the rules.', 'sms' => 'Yekkola: your account was closed for breaking the rules.'],
    ],
    'course_taken_down' => ['title' => 'Course taken down', 'body' => '“:course” was taken down by the Yekkola team: :reason'],
    'warning' => ['title' => 'Warning from the Yekkola team', 'body' => 'Some of your content breaks the rules: :note'],
];
