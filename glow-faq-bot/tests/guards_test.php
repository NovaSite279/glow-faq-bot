<?php
declare(strict_types=1);

// Offline tests for the code-side guards. No API key needed: php tests/guards_test.php

require __DIR__ . '/../src/Bot.php';

use Glow\Bot;

$fails = 0;
function check(string $name, bool $ok): void
{
    global $fails;
    echo ($ok ? "  ok   " : "  FAIL ") . $name . PHP_EOL;
    if (!$ok) $fails++;
}

$faq = file_get_contents(__DIR__ . '/../faq.md');

/** A fake model that returns canned replies in order and records what it was sent. */
function fake(array $replies, ?array &$seen = null): callable
{
    $seen = [];
    return function (array $messages) use (&$replies, &$seen) {
        $seen[] = $messages;
        return array_shift($replies) ?? '{"reply":"(no more canned replies)","action":"answer"}';
    };
}

echo "Rand amounts\n";
check('reads R350, R1 300 and R1,300', Bot::amounts('from R350, total R1 300 or R1,300') == [350, 1300]);
check('ignores the phone number and "under 12"', Bot::amounts('Call 011 000 0000, kids under 12') == []);

echo "Booking claims\n";
check('"You\'re booked for Saturday" is caught', Bot::claimsBooking("You're booked for Saturday at 10!"));
check('"Your appointment is confirmed" is caught', Bot::claimsBooking('Great, your appointment is confirmed.'));
check('"See you on Saturday" is caught', Bot::claimsBooking('See you on Saturday!'));
check('"I can\'t confirm bookings" is allowed', !Bot::claimsBooking("I can't confirm bookings myself, but I can pass your details to the team."));
check('"Saturdays are usually fully booked" is allowed', !Bot::claimsBooking('Saturdays are usually fully booked, so I\'ll pass this to the team.'));

echo "Asking for a person\n";
foreach (['I want to speak to someone', 'Can I talk to a real person?', 'let me chat with the manager', 'human please', 'Kan ek met iemand praat?', 'Ek wil met \'n mens praat', 'Ngifuna ukukhuluma nomuntu'] as $m) {
    check("handoff: $m", Bot::asksForPerson($m));
}
foreach (['How much is a cut?', 'Do you have someone who does braids?', 'Can my son come with me?'] as $m) {
    check("no handoff: $m", !Bot::asksForPerson($m));
}

echo "Parsing the model's output\n";
check('clean JSON', Bot::parse('{"reply":"Hi","action":"answer"}') === ['Hi', 'answer']);
check('JSON in a code fence', Bot::parse("```json\n{\"reply\":\"Hi\",\"action\":\"unknown\"}\n```") === ['Hi', 'unknown']);
check('plain text falls back to answer', Bot::parse('Hello there') === ['Hello there', 'answer']);
check('unknown action becomes answer', Bot::parse('{"reply":"Hi","action":"dance"}') === ['Hi', 'answer']);

echo "Bot behaviour with a fake model\n";
$bot = new Bot(fake([]), $faq);
$r = $bot->respond([], 'I want to speak to someone');
check('asking for a person hands off without calling the model', $r['action'] === 'handoff');

$bot = new Bot(fake([
    '{"reply":"Highlights from R950 and a cut from R350, so about R1300 in total.","action":"answer"}',
    '{"reply":"Highlights are from R950 and a women\'s cut and blow-dry from R350. Final prices are confirmed at your consultation.","action":"answer"}',
], $seen), $faq);
$r = $bot->respond([], 'How much for highlights and a cut?');
check('an invented total is rejected and retried', count($seen) === 2 && !str_contains($r['reply'], '1300'));
check('the retry tells the model what went wrong', str_contains(end($seen[1])['content'], 'R1300'));

$bot = new Bot(fake([
    '{"reply":"You\'re booked for Saturday at 10!","action":"answer"}',
    '{"reply":"Done, see you on Saturday!","action":"answer"}',
]), $faq);
$r = $bot->respond([], 'Can I book Saturday at 10?');
check('two booking claims in a row fall back to the safe reply', $r['action'] === 'booking' && str_contains($r['reply'], "can't confirm"));

$bot = new Bot(fake([
    '{"reply":"Highlights are from R950 and a cut from R350.","action":"answer"}',
    '{"reply":"A cut takes about 45 minutes. I don\'t have a time for highlights, I can ask the team.","action":"unknown"}',
], $seen), $faq);
$r1 = $bot->respond([], 'How much for highlights and a cut?');
$r2 = $bot->respond($r1['history'], 'and how long does that take?');
$sent = array_column($seen[1], 'content');
check('the follow-up sends the earlier question and answer to the model', in_array('How much for highlights and a cut?', $sent, true) && in_array('Highlights are from R950 and a cut from R350.', $sent, true));
check('history keeps both turns', count($r2['history']) === 4);

$bot = new Bot(function () { throw new RuntimeException('network down'); }, $faq);
$r = @$bot->respond([], 'Hi');
check('an API failure gives a friendly reply with the phone number', $r['action'] === 'error' && str_contains($r['reply'], '011 000 0000'));

echo PHP_EOL . ($fails ? "$fails test(s) failed" : 'All tests passed') . PHP_EOL;
exit($fails ? 1 : 0);
