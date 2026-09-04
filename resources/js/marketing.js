/**
 * Public marketing site motion.
 *
 * GSAP is loaded from a CDN by the Blade view rather than bundled, so that
 * adding this page costs the application no new npm dependency. If GSAP does
 * not arrive, every guard below fails closed and the page stays fully readable
 * — all reveals use gsap.from(), so the DOM's resting state is the final one.
 */

function init() {
    if (!window.gsap) {
        return;
    }

    const { gsap, ScrollTrigger } = window;

    gsap.registerPlugin(ScrollTrigger);

    /* The nav hides on the way down and returns on the way up. */
    const nav = document.getElementById('nav');
    let last = 0;

    if (nav) {
        window.addEventListener(
            'scroll',
            () => {
                const y = window.scrollY;
                nav.classList.toggle('up', y > 160 && y > last);
                last = y;
            },
            { passive: true },
        );
    }

    const mm = gsap.matchMedia();

    mm.add('(prefers-reduced-motion: no-preference)', () => {
        /* The signature move: every hairline draws in from the left. */
        gsap.utils.toArray('[data-rule]').forEach((rule) => {
            gsap.from(rule, {
                scaleX: 0,
                duration: 1.15,
                ease: 'expo.out',
                scrollTrigger: { trigger: rule, start: 'top 92%' },
            });
        });

        /* Headline lines rise out of their own mask. */
        gsap.utils.toArray('[data-lines]').forEach((heading) => {
            heading.querySelectorAll(':scope > span').forEach((line) => {
                const mask = document.createElement('span');
                mask.className = 'mask';
                line.parentNode.insertBefore(mask, line);
                mask.appendChild(line);
            });

            gsap.from(heading.querySelectorAll('.mask > span'), {
                yPercent: 108,
                duration: 1,
                ease: 'expo.out',
                stagger: 0.07,
                scrollTrigger: { trigger: heading, start: 'top 88%' },
            });
        });

        /*
         * The hero is above the fold, so it plays on load and never on scroll.
         * A scroll-triggered gsap.from() up here would apply its "from" state
         * immediately and then sit there invisible, because the trigger point
         * is already behind the viewport when the page opens.
         */
        const manifesto = document.querySelector('[data-words]');

        if (manifesto) {
            manifesto.innerHTML = manifesto.innerHTML.replace(
                /(^|>)([^<]+)/g,
                (_, tag, text) =>
                    tag +
                    text.replace(
                        /(\S+)(\s*)/g,
                        '<i style="font-style:normal;display:inline-block">$1</i>$2',
                    ),
            );
        }

        gsap.timeline({ defaults: { ease: 'expo.out' } })
            .from('[data-hero]', { opacity: 0, y: 24, duration: 0.9, stagger: 0.12 })
            .from('#hero-rule', { scaleX: 0, duration: 1.15 }, 0.15)
            .from(
                manifesto ? manifesto.querySelectorAll('i') : [],
                { opacity: 0, yPercent: 42, duration: 0.75, stagger: 0.012 },
                0.3,
            )
            .from('.manifesto .pill', { opacity: 0, y: 16, duration: 0.6 }, 0.75);

        gsap.utils.toArray('[data-fade]').forEach((el) => {
            gsap.from(el, {
                opacity: 0,
                y: 26,
                duration: 0.95,
                ease: 'expo.out',
                scrollTrigger: { trigger: el, start: 'top 90%' },
            });
        });

        /* Product rows: the panel settles out of a slight scale as text rises. */
        gsap.utils.toArray('[data-row]').forEach((row) => {
            const media = row.querySelector('.row-media > *');
            const tl = gsap.timeline({ scrollTrigger: { trigger: row, start: 'top 86%' } });

            if (media) {
                tl.from(media, { scale: 1.09, duration: 1.2, ease: 'expo.out' }, 0);
            }

            tl.from(
                row.children,
                { opacity: 0, y: 30, duration: 0.85, ease: 'expo.out', stagger: 0.06 },
                0,
            );
        });

        document.querySelectorAll('.row').forEach((row) => {
            const arrow = row.querySelector('.row-arrow');

            if (!arrow) {
                return;
            }

            row.addEventListener('pointerenter', () =>
                gsap.to(arrow, { x: 9, duration: 0.5, ease: 'expo.out' }),
            );
            row.addEventListener('pointerleave', () =>
                gsap.to(arrow, { x: 0, duration: 0.5, ease: 'expo.out' }),
            );
        });

        gsap.to('.hindex', {
            yPercent: -7,
            ease: 'none',
            scrollTrigger: {
                trigger: '.manifesto',
                start: 'top bottom',
                end: 'bottom top',
                scrub: 0.6,
            },
        });

        const marquee = document.getElementById('stack-marquee');

        if (marquee) {
            marquee.innerHTML += marquee.innerHTML;
            const half = marquee.scrollWidth / 2;
            gsap.to(marquee, { x: -half, duration: half / 90, ease: 'none', repeat: -1 });
        }

        const cursor = document.getElementById('cursor');

        if (cursor && window.matchMedia('(hover: hover)').matches) {
            const toX = gsap.quickTo(cursor, 'x', { duration: 0.32, ease: 'power3.out' });
            const toY = gsap.quickTo(cursor, 'y', { duration: 0.32, ease: 'power3.out' });

            window.addEventListener('pointermove', (event) => {
                gsap.set(cursor, { opacity: 1 });
                toX(event.clientX - 4.5);
                toY(event.clientY - 4.5);
            });

            document.querySelectorAll('a, button, .row').forEach((el) => {
                el.addEventListener('pointerenter', () =>
                    gsap.to(cursor, { scale: 3.6, duration: 0.35, ease: 'expo.out' }),
                );
                el.addEventListener('pointerleave', () =>
                    gsap.to(cursor, { scale: 1, duration: 0.35, ease: 'expo.out' }),
                );
            });
        }
    });

    /* Web fonts change every measurement ScrollTrigger cached. */
    if (document.fonts?.ready) {
        document.fonts.ready.then(() => ScrollTrigger.refresh());
    }

    window.addEventListener('load', () => ScrollTrigger.refresh());
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
