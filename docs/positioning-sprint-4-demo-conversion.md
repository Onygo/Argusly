# Argusly Positioning Sprint 4: Demo Conversion Optimization

## Conversion Improvements

- Homepage hero CTA changed from generic demo language to an AI Visibility Scan request.
- Homepage now shows sample opportunity output before the broader problem section.
- Trust messaging now explicitly covers human review, governance, CMS integrations, and no platform lock-in.
- Solution pages now use intent-specific CTAs:
  - AI Visibility: Request an AI Visibility Scan.
  - Opportunity Intelligence: Discover growth opportunities.
  - Competitive Intelligence: See your competitive gaps.
  - Lean team solution: Discover scalable marketing opportunities.
- Pricing messaging now leads with outcomes, impact, and execution scalability instead of credits as the main story.
- Contact/demo intake now captures market context needed to tailor a useful demo.

## Changed CTA Strategy

Primary CTA language should map to the page intent:

- Homepage: Request an AI Visibility Scan.
- AI Visibility pages: Request an AI Visibility Scan.
- Opportunity pages: Discover growth opportunities.
- Competitive pages: See your competitive gaps.
- Pricing: Compare growth outcomes or Plan an AI Visibility Scan.
- Enterprise/pricing custom: Plan enterprise rollout.

Avoid generic CTAs such as "Request demo" unless the surrounding context already makes the value specific.

## Demo Flow Design

1. Visitor lands on homepage, market page, solution page, pricing, or contact.
2. Primary CTA sends them to the contact form with source URL and CTA context preserved.
3. Intake form captures:
   - Website.
   - Market.
   - Competitors.
   - Main growth goal.
   - Interest area.
4. Submission email and admin overview expose intake details for follow-up.
5. Sales/demo follow-up can frame the call around a first scan:
   - AI visibility gaps.
   - Competitor answer dominance.
   - Content refresh opportunities.
   - LinkedIn distribution opportunities.

## Form Design

Required fields stay lightweight:

- Name.
- Email.
- Message.

Optional demo-intake fields add conversion context without blocking submission:

- Website.
- Market.
- Competitors.
- Main growth goal.
- Interest area: AI Visibility, Opportunity Intelligence, Competitive Intelligence, Agentic Marketing.

Recommended next iteration: make website and interest area required only when request type is demo.

## Conversion Audit

Homepage:

- Friction point: Previous first-screen CTA was generic.
- Improvement: CTA now promises a specific scan and early sample-output blocks show what the visitor gets.

Solution pages:

- Friction point: All solution pages used similar demo language.
- Improvement: CTA now matches the solution promise and should increase message scent.

Market pages:

- Friction point: Market CTA is useful but still broad.
- Next improvement: Pre-fill market and interest area from the market page in the demo form.

Pricing:

- Friction point: Credits and capacity details can distract from business outcomes.
- Improvement: Pricing copy now frames capacity around visibility, competitive response, refresh upside, and governed execution.

Contact:

- Friction point: Form did not capture enough demo context.
- Improvement: New intake fields enable a tailored scan-oriented response.

Demo flow:

- Friction point: Source CTA was tracked, but the request type select was not stored.
- Improvement: Request type now stores in the existing topic field.

## A/B Test Suggestions

- Homepage CTA:
  - A: Request an AI Visibility Scan.
  - B: See Your Competitive Gaps.
- Sample output placement:
  - A: Directly below the signal loop.
  - B: Inside the hero right column as a compact scan preview.
- Form friction:
  - A: Optional intake fields.
  - B: Website and interest area required for demo requests.
- Pricing CTA:
  - A: Compare growth outcomes.
  - B: Plan an AI Visibility Scan.
- Competitive Intelligence CTA:
  - A: See your competitive gaps.
  - B: Find competitor answer gaps.

## Roadmap

Short term:

- Pre-fill interest area from solution page CTAs.
- Add hidden `source_solution` and `source_market` fields for better attribution.
- Add a compact "what happens after you submit" block beside the demo form.

Medium term:

- Add a dedicated `/demo` route with the same intake form and scan-specific framing.
- Add CRM/event tracking for CTA label, source page, interest area, and request type.
- Add thank-you page variants by interest area.

Long term:

- Generate a lightweight first-scan preview from the submitted website.
- Route leads by interest area and market.
- Build follow-up email templates for each sample opportunity type.
