<?php

namespace App\Support;

/**
 * The single source of truth for what the person at the counter actually says.
 *
 * This is hand-written content, not data: no database, no config, no admin
 * screen. The standalone training page and the embeddable sale-screen widget
 * both read from here, so a script can never drift between the two.
 *
 * Every group has the same shape, so a view can render all three in one loop:
 *
 *   key     string          stable slug, safe in a URL or an Alpine expression
 *   title   string          heading shown to the counter person
 *   blurb   string          one line of context for the group
 *   entries list of entries each: title, lines (list of strings), note (?string)
 *
 * House rule for the copy itself: it is honest. Nothing in here tells a
 * customer that a cheaper oil will hurt their engine, and nothing makes a
 * phone number a condition of service.
 */
final class CounterScripts
{
    public const CHECK_IN = 'check-in';

    public const UPSELL = 'upsell';

    public const OBJECTIONS = 'objections';

    /**
     * Every group, in the order the counter person meets them.
     *
     * @return list<array{key: string, title: string, blurb: string, entries: list<array{title: string, lines: list<string>, note: string|null}>}>
     */
    public static function all(): array
    {
        return [
            self::checkIn(),
            self::upsell(),
            self::objections(),
        ];
    }

    /**
     * Just the group keys and titles — enough to build tabs or a nav.
     *
     * @return list<array{key: string, title: string}>
     */
    public static function groups(): array
    {
        return array_map(
            static fn (array $group): array => ['key' => $group['key'], 'title' => $group['title']],
            self::all()
        );
    }

    /**
     * One group by key, or null for anything unknown — an unrecognised key is a
     * typo in a link, not a reason to 500 the counter screen.
     *
     * @return array{key: string, title: string, blurb: string, entries: list<array{title: string, lines: list<string>, note: string|null}>}|null
     */
    public static function group(string $key): ?array
    {
        foreach (self::all() as $group) {
            if ($group['key'] === $key) {
                return $group;
            }
        }

        return null;
    }

    /**
     * @return array{key: string, title: string, blurb: string, entries: list<array{title: string, lines: list<string>, note: string|null}>}
     */
    private static function checkIn(): array
    {
        return self::makeGroup(
            self::CHECK_IN,
            'Check-In Prompts',
            'What to say to fill in every field on the sale screen — in order, out loud, without sounding like a form.',
            [
                self::entry(
                    'Customer name',
                    [
                        'Salam, welcome in — what are we looking at today?',
                        'Can I get your name for the invoice?',
                        'How do you spell that? I would rather ask than guess on your receipt.',
                    ],
                    'Use the name once more before they leave. It is the whole difference between a receipt and a regular.',
                ),
                self::entry(
                    'Mobile / WhatsApp number',
                    [
                        'What is the best mobile number for you?',
                        'We send the invoice over on WhatsApp so you have it saved — is this number on WhatsApp too?',
                        'It is only used for your invoice and one reminder when the next change is due. Nothing else.',
                    ],
                    'If they hesitate, move on. The bill is not conditional on the number.',
                ),
                self::entry(
                    'Vehicle model and year',
                    [
                        'Which car is it today — model and year?',
                        'Is that the 1.3 or the 1.6? The engine size changes how many litres go in, so it changes the price.',
                        'Any engine work done on it recently that I should know about?',
                    ],
                    null,
                ),
                self::entry(
                    'License plate',
                    [
                        'Can I grab the plate number? It is how I pull your service history next time.',
                        'That way you never have to remember which oil went in — I will have it on screen.',
                    ],
                    null,
                ),
                self::entry(
                    'Current odometer mileage',
                    [
                        'What is the odometer reading right now? I will print the mileage on the invoice.',
                        'If it is easier, I can read it off the dash myself while it is on the ramp.',
                        'That number is what tells us when the next change is actually due, so it is worth getting exact.',
                    ],
                    'Never estimate the mileage. A guessed reading makes the next reminder useless.',
                ),
                self::entry(
                    'Read it back before you start',
                    [
                        'Let me read that back to you: name, model and year, plate, and mileage on the clock — all correct?',
                        'Anything else you want looked at while it is up?',
                    ],
                    'Ten seconds here saves a reprint later.',
                ),
            ],
        );
    }

    /**
     * @return array{key: string, title: string, blurb: string, entries: list<array{title: string, lines: list<string>, note: string|null}>}
     */
    private static function upsell(): array
    {
        return self::makeGroup(
            self::UPSELL,
            'Upsell Scripts',
            'How to explain what actually separates the three oil grades — then let the customer pick.',
            [
                self::entry(
                    'Open the choice',
                    [
                        'We have three grades that fit your car. Let me tell you what genuinely changes between them, then you pick.',
                        'All three protect the engine properly. What changes is how long they last and how they hold up in heat.',
                    ],
                    'Lead with the fact that every option is a safe option. It makes the rest of the conversation believable.',
                ),
                self::entry(
                    'Conventional (mineral) oil',
                    [
                        'Conventional oil is refined straight from crude. It is the base stock everything else is built on.',
                        'Typical drain interval is around 3,000 to 5,000 km in our traffic and our summers.',
                        'It suits an older car, low annual mileage, or a month where you want the bill kept tight — as long as you are happy coming back sooner.',
                    ],
                    null,
                ),
                self::entry(
                    'Semi-synthetic (synthetic blend)',
                    [
                        'Semi-synthetic is conventional base stock blended with some synthetic. You pay a bit more for a bit more stability.',
                        'Usually 5,000 to 7,500 km between changes.',
                        'This is the sensible middle for most daily drivers — office commute, school run, the odd motorway trip.',
                    ],
                    null,
                ),
                self::entry(
                    'Full synthetic',
                    [
                        'Full synthetic base stock is engineered rather than refined, so the molecules are all the same size. That uniformity is the whole point.',
                        'Usually 7,500 to 10,000 km, and it resists thinning when the engine is hot and loaded.',
                        'It earns its price on short stop-start trips, where the oil never fully warms up and sludge builds, and on turbo or newer tight-tolerance engines that run hotter.',
                        'If the car does 15 km a day crawling through town, this is the grade that actually changes something for you.',
                    ],
                    null,
                ),
                self::entry(
                    'Match the grade to the manual, not to the bill',
                    [
                        'What does your manual ask for — 5W-30, 10W-40? We fit what the maker specifies.',
                        'You are already running full synthetic, so I would not put you back on a blend without telling you first.',
                    ],
                    'Viscosity comes from the manufacturer. Only the base stock is a conversation.',
                ),
                self::entry(
                    'Close by handing the decision over',
                    [
                        'Tell me your budget and how you drive, and I will tell you which one I would put in my own car.',
                        'If they choose the cheaper grade: good choice — I will note the shorter interval on your invoice so you know when to come back.',
                    ],
                    'Never suggest the cheaper oil will damage the engine. It will not. The honest difference is interval and heat tolerance.',
                ),
            ],
        );
    }

    /**
     * @return array{key: string, title: string, blurb: string, entries: list<array{title: string, lines: list<string>, note: string|null}>}
     */
    private static function objections(): array
    {
        return self::makeGroup(
            self::OBJECTIONS,
            'Objection Handlers',
            'Straight answers to what the counter actually hears. Answer once, honestly, then take the customer at their word.',
            [
                self::entry(
                    'Synthetic oil is too expensive.',
                    [
                        'Fair — it is more per litre, no argument.',
                        'The honest maths is that it runs roughly twice as long, so per kilometre the two usually land close to each other.',
                        'If the number still does not work today, semi-synthetic or conventional is a perfectly good choice. I will note the shorter interval on your invoice.',
                    ],
                    'Give the maths once. Do not give it twice.',
                ),
                self::entry(
                    'Why do you need my phone number?',
                    [
                        'Two reasons only: your invoice goes to you on WhatsApp, and I can send one reminder when the next change is due.',
                        'We do not sell it on and we do not send marketing.',
                        'If you would rather not, that is completely fine — I will print the invoice and leave that field blank.',
                    ],
                    'The number is never a condition of service. Ask once, accept the answer.',
                ),
                self::entry(
                    'I will just do it myself.',
                    [
                        'Plenty of people do, and if you have got the ramps and somewhere to take the old oil, it is honestly not difficult.',
                        'What you are paying us for is the disposal, the filter torqued to spec, and a written record of the grade and mileage.',
                        'If you do it yourself, keep a note of the mileage — that is the part people lose.',
                    ],
                    null,
                ),
                self::entry(
                    'The other place quoted me less.',
                    [
                        'That is probably a real quote — worth asking them which oil and how many litres, because the price mostly tracks the grade and the volume.',
                        'If it is the same oil for less, take it. I would.',
                        'If it is a different grade, at least now you know what you are comparing.',
                    ],
                    'Never rubbish another shop. Compare the spec, not the shop.',
                ),
                self::entry(
                    'Do I really need the air or cabin filter too?',
                    [
                        'Maybe not. Let me pull it out and you can look at it yourself.',
                        'If it is clean, it goes straight back in and you pay nothing for it.',
                        'If it is packed with dust, you will see it in a second and you can decide then.',
                    ],
                    'Show the part. Never bill for a filter the customer has not looked at.',
                ),
                self::entry(
                    'Can you do it faster?',
                    [
                        'The job is roughly 20 to 30 minutes, and the drain itself is the part we cannot rush — that is how the old oil actually comes out.',
                        'If you are short on time, I can start now and WhatsApp you the moment it is done.',
                        'Or I can book you into a quieter slot where you are straight in and out.',
                    ],
                    null,
                ),
                self::entry(
                    'Just top it up, do not change it.',
                    [
                        'I can top it up — that is your call and it is cheaper today.',
                        'Worth knowing: topping up adds oil but it does not take out what has already broken down.',
                        'You are at 9,000 km on this one, so a change would do more for you. Still your call.',
                    ],
                    'Top-ups are a legitimate service. Do it if they ask, and say what it does and does not do.',
                ),
                self::entry(
                    'I do not need the invoice.',
                    [
                        'I will print it anyway and it is yours to bin.',
                        'It is the only record of which grade went in and at what mileage, and that is worth having at resale or if anything goes wrong.',
                    ],
                    null,
                ),
            ],
        );
    }

    /**
     * @param  list<array{title: string, lines: list<string>, note: string|null}>  $entries
     * @return array{key: string, title: string, blurb: string, entries: list<array{title: string, lines: list<string>, note: string|null}>}
     */
    private static function makeGroup(string $key, string $title, string $blurb, array $entries): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'blurb' => $blurb,
            'entries' => $entries,
        ];
    }

    /**
     * @param  list<string>  $lines
     * @return array{title: string, lines: list<string>, note: string|null}
     */
    private static function entry(string $title, array $lines, ?string $note = null): array
    {
        return [
            'title' => $title,
            'lines' => $lines,
            'note' => $note,
        ];
    }
}
