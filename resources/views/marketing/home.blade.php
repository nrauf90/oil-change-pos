{{--
    Public marketing site for MNR IT Solutions.

    Served only on the central host (see routes/web.php). It shares nothing
    with layouts/app.blade.php on purpose: that layout is the signed-in
    workshop shell, and this page has the opposite job.

    Sample figures below are illustrative and labelled as such in the footer.
    No client names, logos, testimonials or award claims appear anywhere —
    add those only once they are real.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MNR IT Solutions — Business Systems &amp; Custom Software</title>
    <meta name="description" content="MNR IT Solutions builds the systems businesses run on — POS, HRM, CRM and ERP on one platform — plus custom web applications and Android/iOS apps.">

    {{-- Same family and provider the application already uses, plus the 700
         weight this page needs for display type. --}}
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet">

    @vite(['resources/css/marketing.css', 'resources/js/marketing.js'])
</head>
<body>

<div class="cur" id="cursor" aria-hidden="true"></div>

<header class="nav edge" id="nav">
    {{--
        LOGO SLOT — drop the real mark in here.

        Replace the .brand-text span with <img src="{{ asset('images/logo.svg') }}"
        alt="MNR IT Solutions">. Height is capped at 26px; a wide mark just gets
        wider. The nav sits in mix-blend-mode: difference, so a WHITE logo reads
        correctly over both the light page and the dark sections. For a
        full-colour mark, drop `mix-blend-mode: difference` from .nav in
        resources/css/marketing.css and set .nav { color: var(--ink) }.
    --}}
    <a class="brand" href="#top" aria-label="MNR IT Solutions — home">
        <span class="brand-text">MNR IT Solutions</span>
    </a>

    <nav class="nav-mid">
        <a href="#work">Products</a>
        <a href="#services">Services</a>
        <a href="#process">Process</a>
        <a href="#engagement">Engagement</a>
        <a href="#contact">Contact</a>
    </nav>

    <span class="nav-dots" aria-hidden="true"><i></i><i></i><i></i></span>
</header>

<main id="top">

    {{-- ============================================================ HERO --}}
    <section class="hero edge">
        <div class="creds" data-hero>
            <p class="micro">Point of sale · HRM · CRM · ERP<br>on one platform</p>
            <p class="micro">Custom web applications<br>&amp; Android / iOS</p>
            <p class="micro">Cloud or on-premise<br>source code handed over</p>
        </div>

        <div class="rule" id="hero-rule"></div>

        <div class="manifesto">
            <div>
                <p class="manifesto-txt" data-words>MNR IT Solutions builds the systems businesses actually run on — the till, the payroll, the follow-up, the stock — and the custom web and mobile work that off-the-shelf can't reach.</p>
                <a class="pill" href="#work">
                    See the products
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                </a>
            </div>

            <div class="hindex" data-hero>
                <div class="hindex-row"><span class="hindex-code">POS</span><span class="hindex-what">Counter &amp; workshop billing</span><span class="micro">Shipping</span></div>
                <div class="hindex-row"><span class="hindex-code">HRM</span><span class="hindex-what">Attendance, payroll, leave</span><span class="micro">Shipping</span></div>
                <div class="hindex-row"><span class="hindex-code">CRM</span><span class="hindex-what">Leads, follow-ups, quotations</span><span class="micro">Shipping</span></div>
                <div class="hindex-row"><span class="hindex-code">ERP</span><span class="hindex-what">Stock, purchase, accounts</span><span class="micro">Shipping</span></div>
                <div class="hindex-row"><span class="hindex-code">Custom</span><span class="hindex-what">Built around your workflow</span><span class="micro">Scoped</span></div>
                <div class="hindex-row"><span class="hindex-code">Mobile</span><span class="hindex-what">Android &amp; iOS companions</span><span class="micro">Scoped</span></div>
                <p class="micro hindex-foot">One login · one database · one report set</p>
            </div>
        </div>
    </section>

    {{-- =================================================== MEGA STATEMENT --}}
    <section class="sec edge">
        <div class="mega">
            <h2 class="lines" data-lines>
                <span>MNR builds</span>
                <span>the systems</span>
                <span>your shop floor</span>
                <span>runs on.</span>
            </h2>
            <div class="mega-media" data-fade>
                <div class="dp">
                    <div class="dp-top"><span>Branch 02 — Till 1</span><span>INV-40817</span></div>
                    <div class="dp-r"><span>1 × Shell Helix 5W-30 4L</span><span>6,850.00</span></div>
                    <div class="dp-r"><span>1 × Oil filter — Toyota</span><span>1,250.00</span></div>
                    <div class="dp-r"><span>1 × Labour — oil change</span><span>800.00</span></div>
                    <div class="dp-r"><span>Subtotal</span><span>8,900.00</span></div>
                    <div class="dp-r dp-neg"><span>Discount — loyalty</span><span>-400.00</span></div>
                    <div class="dp-r dp-sum"><span>Total PKR</span><span>8,500.00</span></div>
                </div>
            </div>
        </div>
    </section>

    {{-- ======================================================== PRODUCTS --}}
    <section class="edge" id="work">
        <div class="rowhead">
            <h2 class="lines" data-lines><span>Selected products</span></h2>
            <p class="micro">Four systems · one login · one database</p>
        </div>
        <div class="rule" data-rule></div>

        <article class="row" data-row>
            <div class="row-media">
                <div class="dp">
                    <div class="dp-top"><span>POS — shift close</span><span>06:41 PM</span></div>
                    <div class="dp-r"><span>Invoices</span><span>284</span></div>
                    <div class="dp-r"><span>Cash</span><span>612,400.00</span></div>
                    <div class="dp-r"><span>Card &amp; wallet</span><span>318,900.00</span></div>
                    <div class="dp-r dp-neg"><span>Returns</span><span>-12,300.00</span></div>
                    <div class="dp-r dp-sum"><span>Drawer variance</span><span>0.00</span></div>
                </div>
            </div>
            <div>
                <p class="row-index">01 / Point of sale</p>
                <h3 class="row-title">MNR POS</h3>
                <p class="row-sub">Billing that keeps up with a busy counter — barcode or search, split payments, held bills, returns, and a shift-end cash drawer that actually reconciles.</p>
                <ul class="row-tags">
                    <li>Barcode billing</li><li>Split payments</li><li>Held bills</li>
                    <li>Cash drawer</li><li>Measured stock</li><li>Thermal print</li><li>Offline-tolerant</li>
                </ul>
            </div>
            <span class="row-arrow" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></span>
        </article>
        <div class="rule" data-rule></div>

        <article class="row" data-row>
            <div class="row-media">
                <div class="dp">
                    <div class="dp-top"><span>Payroll — Aug 2026</span><span>34 staff</span></div>
                    <div class="dp-r"><span>Basic</span><span>1,842,000</span></div>
                    <div class="dp-r"><span>Overtime — 214 hrs</span><span>126,400</span></div>
                    <div class="dp-r dp-neg"><span>Advances</span><span>-68,900</span></div>
                    <div class="dp-att" role="img" aria-label="Attendance week: five present, two off">
                        <i class="on">P</i><i class="on">P</i><i class="on">P</i><i>A</i><i>L</i><i class="on">P</i><i class="on">P</i>
                    </div>
                    <div class="dp-r dp-sum"><span>Net payable PKR</span><span>1,899,500</span></div>
                </div>
            </div>
            <div>
                <p class="row-index">02 / People</p>
                <h3 class="row-title">MNR HRM</h3>
                <p class="row-sub">Attendance off a biometric device or a phone, leave that follows your own policy, and a payroll run you can close without a spreadsheet.</p>
                <ul class="row-tags">
                    <li>Biometric attendance</li><li>Shifts &amp; rosters</li><li>Leave</li>
                    <li>Overtime rules</li><li>Payslips</li><li>Loans</li><li>EOBI &amp; tax</li>
                </ul>
            </div>
            <span class="row-arrow" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></span>
        </article>
        <div class="rule" data-rule></div>

        <article class="row" data-row>
            <div class="row-media">
                <div class="dp">
                    <div class="dp-top"><span>Pipeline — this month</span><span>54 open</span></div>
                    <div class="dp-bar" role="img" aria-label="24 new, 16 contacted, 9 quoted, 5 won">
                        <b style="flex:24"></b><b style="flex:16"></b><b style="flex:9"></b><b style="flex:5"></b>
                    </div>
                    <div class="dp-r"><span>New</span><span>24</span></div>
                    <div class="dp-r"><span>Contacted</span><span>16</span></div>
                    <div class="dp-r"><span>Quoted</span><span>9</span></div>
                    <div class="dp-r dp-sum"><span>Won</span><span>5</span></div>
                </div>
            </div>
            <div>
                <p class="row-index">03 / Customers</p>
                <h3 class="row-title">MNR CRM</h3>
                <p class="row-sub">Every enquiry logged, every follow-up dated, every quotation versioned — so nothing dies quietly in a salesman's WhatsApp.</p>
                <ul class="row-tags">
                    <li>Lead capture</li><li>Pipeline stages</li><li>Reminders</li>
                    <li>Quotations</li><li>Call logs</li><li>WhatsApp templates</li><li>Targets</li>
                </ul>
            </div>
            <span class="row-arrow" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></span>
        </article>
        <div class="rule" data-rule></div>

        <article class="row" data-row>
            <div class="row-media">
                <div class="dp">
                    <div class="dp-top"><span>Stock — Branch 02</span><span>On hand / reorder</span></div>
                    <div class="dp-r"><span>Shell Helix 5W-30 4L</span><span>38 / 20</span></div>
                    <div class="dp-r dp-neg"><span>Oil filter TY-90</span><span>6 / 25</span></div>
                    <div class="dp-r"><span>Coolant concentrate 1L</span><span>71 / 30</span></div>
                    <div class="dp-r"><span>Wiper blade 24"</span><span>44 / 15</span></div>
                    <div class="dp-r dp-sum"><span>Stock value PKR</span><span>4,318,600</span></div>
                </div>
            </div>
            <div>
                <p class="row-index">04 / Operations</p>
                <h3 class="row-title">MNR ERP</h3>
                <p class="row-sub">Purchase through to payment, stock across every branch, and a ledger that closes — with reorder levels that warn you before the shelf is empty.</p>
                <ul class="row-tags">
                    <li>Stock &amp; batches</li><li>Branch transfers</li><li>PO &amp; GRN</li>
                    <li>Supplier ledger</li><li>Chart of accounts</li><li>Trial balance</li><li>P&amp;L</li>
                </ul>
            </div>
            <span class="row-arrow" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></span>
        </article>
        <div class="rule" data-rule></div>

        <div class="sec" style="padding-block: clamp(48px, 6vw, 90px)">
            <div class="mega">
                <h2 class="lines" data-lines>
                    <span>Bought separately,</span>
                    <span>never stitched</span>
                    <span>together.</span>
                </h2>
                <div data-fade>
                    <p class="row-sub" style="max-width: 40ch; margin: 0 0 26px">Most businesses end up with four systems and four versions of the truth. Everything MNR ships sits on one spine, so it can't drift apart.</p>
                    <ul class="spine">
                        <li><h3>One user directory</h3></li>
                        <li><h3>One item &amp; party master</h3></li>
                        <li><h3>One reporting layer</h3></li>
                        <li><h3>One audit trail</h3></li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    {{-- ======================================================== SERVICES --}}
    <section class="dark sec edge" id="services">
        <div class="rowhead">
            <h2 class="lines" data-lines><span>When the product</span><span>isn't the answer</span></h2>
            <p class="micro">Build services</p>
        </div>
        <div class="rule" data-rule style="margin-bottom: clamp(40px, 5vw, 70px)"></div>

        <div class="svc-grid">
            <div class="svc" data-fade>
                <p class="micro micro-b">01 — Web / full stack</p>
                <h3>Custom web<br>applications</h3>
                <p>Portals, dashboards, booking and dispatch systems, distributor networks, and the internal tool that finally replaces the spreadsheet nobody wants to touch.</p>
                <ul>
                    <li><b>01</b><span>Discovery and a written scope before a line of code</span></li>
                    <li><b>02</b><span>Role-based access, audit logging and backups from day one</span></li>
                    <li><b>03</b><span>Integrations: payment gateways, WhatsApp, biometric devices, courier and tax APIs</span></li>
                    <li><b>04</b><span>Source code and database handed over — no lock-in</span></li>
                </ul>
            </div>

            <div class="svc" data-fade>
                <p class="micro micro-b">02 — Mobile / Android &amp; iOS</p>
                <h3>Mobile app<br>development</h3>
                <p>Apps that talk to the same backend as your web system — an order app for the salesman, a delivery app for the rider, a booking app for your customers.</p>
                <ul>
                    <li><b>01</b><span>Offline-first sync for staff who lose signal mid-round</span></li>
                    <li><b>02</b><span>Barcode scanning, camera capture, GPS check-in, push</span></li>
                    <li><b>03</b><span>Play Store and App Store submission handled for you</span></li>
                    <li><b>04</b><span>One shared API — the app and the web system never disagree</span></li>
                </ul>
            </div>
        </div>

        <div class="mq" aria-label="Technology stack">
            <div class="mq-track" id="stack-marquee">
                <span>Laravel</span><span>PHP 8.3</span><span>Livewire</span><span>Filament</span>
                <span>Tailwind</span><span>MySQL</span><span>Redis</span><span>Flutter</span>
                <span>React Native</span><span>Kotlin</span><span>Swift</span><span>Firebase</span>
            </div>
        </div>
    </section>

    {{-- ========================================================= PROCESS --}}
    <section class="sec edge" id="process">
        <div class="rowhead">
            <h2 class="lines" data-lines><span>Four stages,</span><span>in this order</span></h2>
            <p class="micro">How we work</p>
        </div>
        <div class="rule" data-rule></div>

        <div class="step" data-row>
            <span class="step-n">01</span>
            <h3>Scope</h3>
            <p>We sit with the people who will actually use it, walk your process end to end, and write down what the system must do — and what it deliberately won't.</p>
            <p class="micro step-when">3–5 days</p>
        </div>
        <div class="rule" data-rule></div>

        <div class="step" data-row>
            <span class="step-n">02</span>
            <h3>Build</h3>
            <p>Products get your branches, users, tax rules and item master loaded. Custom work is built in two-week blocks you can see running, not just read about.</p>
            <p class="micro step-when">2 weeks – 4 months</p>
        </div>
        <div class="rule" data-rule></div>

        <div class="step" data-row>
            <span class="step-n">03</span>
            <h3>Pilot</h3>
            <p>One branch, one shift, real transactions, with us on call. We fix whatever the first week exposes before anyone else is asked to switch over.</p>
            <p class="micro step-when">1–2 weeks</p>
        </div>
        <div class="rule" data-rule></div>

        <div class="step" data-row>
            <span class="step-n">04</span>
            <h3>Roll out</h3>
            <p>Remaining branches go live, your staff are trained on their own data, and you get a named support contact — not a ticket queue.</p>
            <p class="micro step-when">Ongoing</p>
        </div>
        <div class="rule" data-rule></div>
    </section>

    {{-- ====================================================== ENGAGEMENT --}}
    <section class="sec edge" id="engagement" style="padding-top: 0">
        <div class="rowhead">
            <h2 class="lines" data-lines><span>Three ways</span><span>to start</span></h2>
            <p class="micro">Quoted after scoping — never off a web page</p>
        </div>
        <div class="rule" data-rule style="margin-bottom: clamp(36px, 4vw, 60px)"></div>

        <div class="plans">
            <div class="plan" data-fade>
                <p class="micro micro-b">Option A</p>
                <h3>License a product</h3>
                <p>Your process is standard and you want to be live this month.</p>
                <ul>
                    <li>One product, standard modules</li>
                    <li>Setup, data import and staff training</li>
                    <li>Cloud hosting or your own server</li>
                    <li>Updates and support included</li>
                </ul>
                <p class="micro plan-note">Per branch · monthly or yearly</p>
            </div>

            <div class="plan" data-fade>
                <p class="micro micro-b">Option B — most chosen</p>
                <h3>Product + customisation</h3>
                <p>The product covers 80% and the rest is how you actually work.</p>
                <ul>
                    <li>Any combination of POS, HRM, CRM and ERP</li>
                    <li>Custom screens, reports and approval rules</li>
                    <li>Integrations with the tools you already use</li>
                    <li>Optional companion mobile app</li>
                </ul>
                <p class="micro plan-note">License + one-time development quote</p>
            </div>

            <div class="plan" data-fade>
                <p class="micro micro-b">Option C</p>
                <h3>Dedicated build</h3>
                <p>Your process is the product and nothing off the shelf fits it.</p>
                <ul>
                    <li>Web application built to your spec</li>
                    <li>Android and iOS apps on one shared API</li>
                    <li>Source code and database handed over</li>
                    <li>Optional retainer for changes and support</li>
                </ul>
                <p class="micro plan-note">Fixed-scope quote or monthly retainer</p>
            </div>
        </div>

        <dl class="facts" data-fade>
            <div><dt class="micro">Deployment</dt><dd>Cloud, or your own server</dd></div>
            <div><dt class="micro">Data ownership</dt><dd>Yours — full export any time</dd></div>
            <div><dt class="micro">Languages</dt><dd>English and Urdu</dd></div>
            <div><dt class="micro">Support</dt><dd>Named contact, phone &amp; WhatsApp</dd></div>
            <div><dt class="micro">Backups</dt><dd>Daily, restore tested</dd></div>
        </dl>
    </section>

</main>

{{-- ========================================================== FOOTER CTA --}}
<footer class="dark edge" id="contact">
    <div class="sec fcta" style="padding-bottom: clamp(40px, 5vw, 70px)">
        <h2 class="lines" data-lines>
            <span>Let's</span>
            <span>build.</span>
        </h2>
        <p class="row-sub" style="max-width: 40ch; margin-top: clamp(24px, 3vw, 40px)">Bring a day's invoices, an attendance sheet or a stock register to the demo. We'll show you that same day inside the system.</p>

        <div class="fcontact">
            <a class="fbig" href="mailto:{{ $email }}">{{ $email }}</a>
            <a class="fbig" href="https://wa.me/{{ $whatsappDigits }}" target="_blank" rel="noopener">WhatsApp {{ $whatsapp }}</a>
        </div>
    </div>

    <div class="rule" data-rule></div>

    <div class="fgrid">
        <div>
            <h4>MNR IT Solutions</h4>
            <p class="micro" style="max-width: 30ch">Business software and custom development for shops, workshops, distributors and offices.</p>
        </div>
        <div>
            <h4>Products</h4>
            <ul>
                <li><a href="#work">MNR POS</a></li>
                <li><a href="#work">MNR HRM</a></li>
                <li><a href="#work">MNR CRM</a></li>
                <li><a href="#work">MNR ERP</a></li>
            </ul>
        </div>
        <div>
            <h4>Services</h4>
            <ul>
                <li><a href="#services">Custom web apps</a></li>
                <li><a href="#services">Mobile apps</a></li>
                <li><a href="#services">Integrations</a></li>
                <li><a href="#process">Support</a></li>
            </ul>
        </div>
        <div>
            <h4>Contact</h4>
            <ul>
                <li><a href="mailto:{{ $email }}">{{ $email }}</a></li>
                <li><a href="https://wa.me/{{ $whatsappDigits }}" target="_blank" rel="noopener">WhatsApp {{ $whatsapp }}</a></li>
                <li><a href="tel:{{ $whatsapp }}">{{ $whatsapp }}</a></li>
            </ul>
            <p class="micro" style="margin-top: 12px">Mon–Sat, 10:00–19:00 PKT</p>
        </div>
    </div>

    <div class="rule" data-rule style="margin-top: clamp(34px, 4vw, 56px)"></div>
    <div class="fnote">
        <span class="micro">© {{ now()->year }} MNR IT Solutions</span>
        <span class="micro">Figures shown are illustrative sample data</span>
    </div>
</footer>

{{-- GSAP from a CDN rather than npm, so this page adds no application
     dependency. resources/js/marketing.js fails closed without it. --}}
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.13.0/gsap.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.13.0/ScrollTrigger.min.js" defer></script>
</body>
</html>
