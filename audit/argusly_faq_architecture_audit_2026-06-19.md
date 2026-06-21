# Argusly FAQ Architecture Audit

Datum: 2026-06-19  
Scope: Argusly marketingwebsite, inclusief homepage, platform, solutions, markets, pricing, contact, security, resource pages en blog templates.  
Doel: FAQ's ontwikkelen van losse contentblokken naar een structurele laag voor AI Visibility, Semantic SEO en conversie.  
Positionering: "Argusly helpt kennisintensieve B2B organisaties kansen te ontdekken, zichtbaarheid in AI systemen te vergroten en marketingactiviteiten autonoom te organiseren."

## Executive Summary

Argusly heeft de juiste thematische basis voor een sterke FAQ-architectuur: AI visibility, opportunity intelligence, agentic marketing, semantic SEO, governance en autonomous marketing workflows. De uitvoering is alleen nog template-gedreven en ongelijk verdeeld.

De huidige situatie:
- Agentic Marketing is het beste voorbeeld: zichtbare FAQ plus FAQPage JSON-LD.
- Marketing topic/resource pages ondersteunen FAQ rendering en FAQPage JSON-LD.
- Blog detail templates ondersteunen FAQ schema wanneer posts `faq_schema` bevatten.
- Pricing heeft zichtbare FAQ's, maar mist FAQPage JSON-LD.
- Homepage, platform, solution, market, contact en security pagina's missen een structurele FAQ-laag.

Architectuuradvies:
- Maak FAQ's een vast contentmodel per paginatype.
- Gebruik per pagina een duidelijke FAQ-taak: homepage voor category clarity, platform voor enterprise evaluation, solutions voor buyer intent, markets voor vertical intent, pricing voor objections, contact voor qualification, security voor trust.
- Render FAQPage JSON-LD vanuit hetzelfde dataobject als de zichtbare FAQ.
- Voeg interne links toe vanuit antwoorden naar de volgende logische evaluatiestap.

## FAQ Architecture Principles

1. Elke FAQ moet een concrete buyer question beantwoorden.
2. De eerste zin moet direct antwoord geven en de entity "Argusly" of het kernconcept expliciet noemen waar relevant.
3. Antwoorden moeten 45-90 woorden zijn voor AI extraction.
4. Elke pagina krijgt unieke vragen; overlapvragen krijgen per pagina een andere invalshoek.
5. Elke FAQ-sectie ondersteunt minimaal een van drie doelen: AI visibility, semantic SEO of conversie.
6. FAQPage JSON-LD wordt alleen toegevoegd wanneer de vraag en het antwoord zichtbaar op de pagina staan.
7. Interne links in FAQ-antwoorden verbinden clusters: homepage -> solutions -> platform -> pricing/contact -> security/resources.

## FAQ Coverage Matrix

| Paginatype | Huidige FAQ | Uniek | Buyer questions | AI Visibility | Semantic SEO | Conversie | FAQPage JSON-LD | Ontbrekende interne links | Ontbrekende onderwerpen | Classificatie |
|---|---:|---:|---:|---:|---:|---:|---:|---|---|---|
| Homepage | Nee | n.v.t. | Laag | Middel | Middel | Middel | Nee | Naar AI Visibility, Opportunity Intelligence, Agentic Marketing, Platform, Pricing | Wat is Argusly, voor wie, verschil met AI writer, eerste scan, governance | C |
| Product overview | Nee | n.v.t. | Hoog | Hoog | Hoog | Hoog | Nee; wel SoftwareApplication | Naar Platform, Pricing, Contact, Security | Platform fit, CMS/integraties, content operations, governance, AI visibility | C |
| Product platform | Nee | n.v.t. | Hoog | Hoog | Hoog | Hoog | Nee; wel SoftwareApplication | Naar Pricing, Contact, Security, Resources | Enterprise evaluation, implementation, roles, approvals, auditability, connectors | C |
| Product capabilities/governance/intelligence legacy pages | Redirects/anchors | n.v.t. | n.v.t. | n.v.t. | n.v.t. | n.v.t. | n.v.t. | Anchors binnen platform | Niet als losse FAQ nodig | D |
| Solution: AI Visibility | Nee | n.v.t. | Hoog | Zeer hoog | Hoog | Hoog | Nee | Naar Opportunity Intelligence, Competitive Intelligence, Contact Scan, Resources | AI visibility vs SEO, measurement, citations, prompts, AI answer share | C |
| Solution: Opportunity Intelligence | Nee | n.v.t. | Hoog | Hoog | Hoog | Hoog | Nee | Naar AI Visibility, Competitive Intelligence, Agentic Marketing, Contact | Difference from keyword research, signals, prioritization, execution paths | C |
| Solution: Competitive Intelligence | Nee | n.v.t. | Hoog | Hoog | Hoog | Hoog | Nee | Naar Opportunity Intelligence, AI Visibility, Contact | Competitor signals, AI answer share, topic ownership, response workflows | C |
| Solution: Marketing Without A Large Team | Nee | n.v.t. | Hoog | Middel | Middel | Hoog | Nee | Naar Agentic Marketing, Platform, Pricing, Contact | Lean team capacity, governance, outsourcing, automation boundaries | C |
| Agentic Marketing | Ja | Goed | Goed | Goed | Goed | Goed | Ja | Naar Platform, AI Visibility, Pricing, Security | Enterprise rollout, measurement, integrations, approval design | A/B |
| Market pages | Nee | n.v.t. | Hoog | Hoog | Hoog | Hoog | Nee; FAQPage genoemd als schema opportunity | Naar relevant solution page, Contact met market subject, Resources | Sector-specific buyer questions, vertical content clusters, schema fit, first scan | C |
| Pricing | Ja | Deels | Goed | Middel | Middel | Hoog | Nee | Naar Platform, Security, Contact, Enterprise | Plan fit, capacity examples, enterprise rollout, procurement/security | B |
| Contact | Nee | n.v.t. | Hoog | Laag | Laag | Hoog | Nee | Naar Pricing, Platform, Security, AI Visibility Scan | Wie moet boeken, voorbereiding, responstijd, enterprise intake, scan aanvragen | C |
| Security | Nee | n.v.t. | Hoog | Laag | Middel | Hoog | Nee | Naar Privacy, Terms, Subprocessors, Contact | Data processing, AI providers, audit logs, permissions, enterprise review | C |
| Legal privacy/terms/subprocessors/cookies | Nee | n.v.t. | Middel | Laag | Laag | Middel | Nee | Onderling naar Security/Contact | Alleen compliance support vragen | D/C |
| Resource marketing topics | Ja | Deels | Middel | Goed | Goed | Middel | Ja | Naar solutions, platform, related blog | Buyer/evaluation intent, use cases, next step CTAs | B |
| Product updates resource template | Nee | n.v.t. | Laag | Laag | Laag | Laag | Nee | Naar roadmap/platform indien publiek actief | Niet publiek actief volgens huidige routing/config | D |
| Blog index/category/tag/RSS | Nee | n.v.t. | Laag | Laag | Laag | Laag | Nee | Naar resources/topics | Geen FAQ nodig op listings | D |
| Blog detail | Afhankelijk van post | Afhankelijk | Afhankelijk | Goed indien gevuld | Goed indien gevuld | Middel | Ja indien `faq_schema` gevuld | Naar solutions, platform, pricing, related posts | Editorial FAQ rules per intent type | B |

## Page-Level Architecture Recommendations

### 1. Homepage

Rol in architectuur: category/entity clarity. De homepage moet Argusly in een paar extractable antwoorden positioneren voor AI systems en nieuwe bezoekers.

Aanbevolen FAQ onderwerpen:
- Wat is Argusly?
- Voor wie is Argusly bedoeld?
- Waarin verschilt Argusly van een AI writer?
- Hoe helpt Argusly bij AI visibility?
- Wat is opportunity intelligence?
- Hoe werkt autonomous marketing met menselijke controle?
- Kan Argusly naast bestaande CMS- en marketingtools werken?
- Wat is een goede eerste stap?

Interne links:
- "AI visibility" -> AI Visibility solution.
- "Opportunity intelligence" -> Opportunity Intelligence solution.
- "Autonomous marketing" -> Agentic Marketing.
- "CMS en publishing" -> Platform.
- "eerste stap" -> Contact of Pricing.

Classificatie: C. Toevoegen.

### 2. Platform Pagina's

Rol in architectuur: enterprise software evaluation. Platform FAQ's moeten buyers helpen beoordelen of Argusly past in hun stack, governance en operating model.

Aanbevolen FAQ onderwerpen:
- Wat doet het Argusly platform?
- Welke workflows ondersteunt Argusly?
- Kan Argusly publiceren naar bestaande systemen?
- Hoe werkt governance in Argusly?
- Hoe ondersteunt Argusly semantic SEO?
- Hoe ondersteunt Argusly AI visibility?
- Is Argusly geschikt voor enterprise evaluatie?
- Hoe voorkom je generieke AI-content?
- Hoe snel kan een team starten?
- Welke rollen moeten betrokken zijn bij implementatie?

Interne links:
- Platform -> Pricing voor plan fit.
- Platform -> Contact voor rollout/integratie.
- Platform -> Security voor enterprise trust.
- Platform -> AI Visibility en Opportunity Intelligence voor capability verdieping.

Classificatie: C. Toevoegen.

### 3. Solution Pagina's

Rol in architectuur: high-intent buyer education. Solution FAQ's moeten zoek- en AI-vragen beantwoorden die buyers stellen voordat zij een demo aanvragen.

AI Visibility FAQ thema's:
- AI visibility vs SEO.
- Hoe AI answer share, mentions en citations meten.
- Waarom rankings niet genoeg zijn.
- Welke content AI systems kunnen gebruiken.
- Wanneer een AI Visibility Scan zinvol is.

Opportunity Intelligence FAQ thema's:
- Opportunity intelligence vs keyword research.
- Welke signalen Argusly gebruikt.
- Hoe kansen worden geprioriteerd.
- Hoe opportunities in workflows veranderen.
- Voor welke teams dit werkt.

Competitive Intelligence FAQ thema's:
- Welke competitor signals tellen.
- Hoe AI answer share van concurrenten wordt gemeten.
- Wat topic ownership betekent.
- Hoe je van tracking naar actie gaat.

Marketing Without A Large Team FAQ thema's:
- Hoe kleine teams capaciteit vergroten.
- Wat Argusly automatiseert en wat niet.
- Hoe governance blijft bestaan.
- Samenwerking met freelancers/agencies.
- Eerste use case voor lean teams.

Interne links:
- AI Visibility -> Contact met subject "AI Visibility Scan", Opportunity Intelligence, Resources.
- Opportunity Intelligence -> Competitive Intelligence, Agentic Marketing, Market pages.
- Competitive Intelligence -> Opportunity Intelligence, AI Visibility, Contact.
- Marketing Without A Large Team -> Agentic Marketing, Platform, Pricing.

Classificatie: C. Toevoegen.

### 4. Market Pagina's

Rol in architectuur: vertical semantic coverage. Elke market FAQ moet de generieke Argusly-positionering vertalen naar sectorvragen.

Vaste FAQ blauwdruk per market:
- Hoe helpt Argusly [sector] organisaties met AI visibility?
- Welke buyer questions missen [sector] websites vaak?
- Hoe ontdekt Argusly content opportunities in [sector]?
- Hoe vergelijkt Argusly concurrenten binnen [sector]?
- Welke content clusters zijn belangrijk voor [sector]?
- Hoe ondersteunt Argusly governance en review in [sector] marketing?
- Welke structured data is relevant voor [sector] pagina's?
- Wat is een goede eerste scan voor een [sector] organisatie?

Vertical modifiers:
- IT Services & SaaS: integrations, comparison pages, API/security objections.
- Consulting & Professional Services: methodology, proof, credentials, service differentiation.
- Recruitment & Staffing: hiring questions, salary intent, employer/candidate journeys, local SEO.
- Telecom & Connectivity: availability, SLA, uptime, migration, coverage.
- Logistics & Supply Chain: lanes, capacity, compliance, quote intent.
- Manufacturing: product families, standards, applications, procurement questions.
- Energy & Industrial Services: safety, compliance, maintenance, certifications.
- Automotive: local dealer intent, models/services, stock/service questions, commercial vehicle journeys.

Interne links:
- Elke market FAQ -> AI Visibility, Opportunity Intelligence, Contact.
- Waar relevant -> Competitive Intelligence en Agentic Marketing.
- Market cross-links alleen wanneer use case overlap logisch is.

Classificatie: C. Toevoegen.

### 5. Pricing

Rol in architectuur: objection handling. Pricing FAQ's moeten plan fit, capaciteit, enterprise requirements en buyer hesitation beantwoorden.

Huidige status:
- Zichtbare FAQ aanwezig.
- Vragen zijn nuttig, maar nog beperkt tot algemene plan/capacity objections.
- FAQPage JSON-LD ontbreekt in de pricing view.

Aanbevolen extra onderwerpen:
- Welk plan past bij een klein team?
- Wanneer is Enterprise nodig?
- Hoe werkt capaciteit in concrete workflows?
- Kan extra capaciteit tijdelijk worden toegevoegd?
- Zijn integraties inbegrepen?
- Hoe werkt onboarding?
- Hoe beoordeel ik ROI?
- Kan security/procurement vooraf meekijken?

Interne links:
- "governance" -> Platform.
- "security review" -> Security.
- "enterprise" -> Contact.
- "AI Visibility Scan" -> AI Visibility solution of Contact.

Classificatie: B. Uitbreiden.

### 6. Contact

Rol in architectuur: conversion qualification. Contact FAQ's moeten bezoekers helpen de juiste aanvraag te doen en frictie rond demo/scan/enterprise verminderen.

Aanbevolen FAQ onderwerpen:
- Waarvoor kan ik contact opnemen?
- Wie moet bij een demo aanwezig zijn?
- Welke informatie helpt vooraf?
- Kan ik een AI Visibility Scan aanvragen?
- Kan Argusly helpen bij enterprise rollout?
- Hoe snel reageert Argusly?
- Kunnen partner- of agencyvragen via dit formulier?
- Wat gebeurt er na het formulier?

Interne links:
- Contact -> Pricing voor planvragen.
- Contact -> Security voor enterprise/procurement.
- Contact -> Platform voor product fit.
- Contact -> AI Visibility voor scans.

Classificatie: C. Toevoegen.

### 7. Security

Rol in architectuur: trust and procurement enablement. Security FAQ's moeten enterprise buyers, legal en technical stakeholders snel helpen beoordelen of Argusly verder onderzocht kan worden.

Aanbevolen FAQ onderwerpen:
- Welke security controls gebruikt Argusly?
- Hoe werkt role-based access?
- Hoe worden klantcontent en AI workflows gecontroleerd?
- Welke externe providers verwerken data?
- Waar staan privacy en subprocessors?
- Ondersteunt Argusly audit logs?
- Hoe werkt workspace-scoped access?
- Kan security/procurement aanvullende vragen stellen?

Interne links:
- Security -> Privacy.
- Security -> Terms.
- Security -> Subprocessors.
- Security -> Contact.
- Security -> Platform governance.

Classificatie: C. Toevoegen.

### 8. Resource Pagina's

Rol in architectuur: topic depth and education. Resource pages moeten definities en explainers verbinden aan commercial pages.

Marketing topic pages:
- FAQ aanwezig en FAQPage JSON-LD aanwezig.
- Uitbreiden met buyer/evaluation intent.
- Voeg "next step" links toe naar solution pages, platform en contact.

Product updates:
- Public template bestaat, maar public access lijkt uitgeschakeld/verwijderd volgens routing/config.
- Geen FAQ nodig zolang de pagina niet publiek actief is.
- Als product updates terugkomen: gebruik geen algemene FAQ; voeg alleen korte "wat betekent deze update voor workflows?" blocks toe per update.

Aanbevolen resource FAQ onderwerpen:
- Wat betekent dit concept voor B2B buyers?
- Wanneer moet een team dit oplossen?
- Welke Argusly workflow ondersteunt dit?
- Welke pagina of scan is de volgende stap?

Classificatie: B voor marketing topics, D voor niet-actieve product updates.

### 9. Blog Templates

Rol in architectuur: scalable answer layer. Blog FAQ's moeten afhankelijk zijn van intent, niet standaard op elke post verschijnen.

Huidige status:
- Blog detail ondersteunt FAQ schema als `faq_schema` bestaat.
- Blog index/category/tag/RSS hebben geen FAQ nodig.

Editorial rules:
- BOFU posts: 5-6 FAQ's met objections, alternatives en CTA.
- Solution posts: 4-6 FAQ's met definition, implementation, measurement en next steps.
- Comparison posts: 5-7 FAQ's met selection criteria en tradeoffs.
- Thought leadership: 2-4 FAQ's alleen wanneer er echte search questions zijn.
- News/product updates: meestal geen FAQPage, tenzij het onderwerp blijvende evaluatievragen oproept.

Interne links:
- Blog answer -> relevante solution.
- Blog answer -> platform capability.
- Blog answer -> pricing/contact bij commercial intent.
- Blog answer -> related resource/topic page.

Classificatie: B. Uitbreiden via editorial governance.

## AI Visibility Impact

De grootste AI Visibility winst zit bij solution en market pages. Deze pagina's beschrijven al concepten die AI-systemen moeten begrijpen, maar missen compacte vraag-antwoordblokken die direct als antwoordbron kunnen dienen.

Impact per laag:
- Homepage: versterkt entity clarity rond "Argusly", "agentic marketing platform", "AI visibility" en "opportunity intelligence".
- Solutions: claimt high-intent answer space rond AI visibility, competitor answer share, opportunity intelligence en lean autonomous marketing.
- Markets: maakt Argusly zichtbaar in vertical prompts zoals "AI visibility for SaaS companies" of "content opportunities for manufacturing websites".
- Platform: helpt AI-systemen Argusly correct classificeren als governed B2B marketing operating layer, niet alleen als AI writer.
- Security: ondersteunt trust prompts in enterprise software evaluation.

Implementatieregel:
- Elke FAQ moet een extractable answer hebben met entity, context, use case en next action.

## SEO Impact

FAQ architectuur verbetert SEO vooral via long-tail coverage, topical completeness en interne linking.

Verwachte SEO effecten:
- Meer dekking op question modifiers: what, how, when, difference, best way, for [industry].
- Sterkere semantic clusters rond AI visibility, semantic SEO, agentic marketing, opportunity intelligence en content operations.
- Betere internal link flow tussen commercial en educational pages.
- Mogelijke rich result eligibility wanneer FAQPage JSON-LD correct wordt toegevoegd.
- Minder keyword cannibalization wanneer elk paginatype een eigen FAQ-functie krijgt.

Overlapregels:
- Homepage beantwoordt "Wat is Argusly?"
- Agentic page beantwoordt "Wat is agentic marketing?"
- AI Visibility page beantwoordt "Wat is AI visibility?"
- Opportunity page beantwoordt "Wat is opportunity intelligence?"
- Pricing beantwoordt "Wat kost/ welk plan/ capaciteit?"
- Platform beantwoordt "Hoe werkt het product in onze stack?"
- Market pages beantwoorden "Hoe werkt dit voor onze sector?"

## Conversion Impact

FAQ's kunnen conversie verbeteren door onzekerheid weg te nemen vlak voor een CTA.

Conversiepunten per pagina:
- Homepage: CTA naar scan/demo na category clarity.
- Platform: CTA naar demo na integratie- en governancevragen.
- Solutions: CTA naar specifieke scan of opportunity review.
- Markets: CTA naar vertical demo of market scan.
- Pricing: CTA naar plan, enterprise contact of scan.
- Contact: betere intakekwaliteit en minder formulierfrictie.
- Security: support voor procurement/security stakeholders.
- Resources/blog: soft conversion naar solution, platform of scan.

CTA-regel:
- Elk FAQ-antwoord met commercial intent eindigt conceptueel met een interne next step, maar blijft inhoudelijk nuttig en niet salesy.

## Prioriteitenlijst

1. Voeg FAQPage JSON-LD toe aan Pricing.
   Reden: quick win; zichtbare FAQ bestaat al.

2. Voeg FAQ-secties toe aan AI Visibility en Opportunity Intelligence solution pages.
   Reden: hoogste AI answer en commercial search potentieel.

3. Voeg FAQ-sectie toe aan Product Platform.
   Reden: enterprise evaluation en conversion enablement.

4. Voeg FAQ data/rendering toe aan Market pages.
   Reden: vertical semantic SEO en AI visibility op lange termijn.

5. Voeg FAQ toe aan Contact.
   Reden: betere lead qualification en minder frictie.

6. Voeg Security FAQ toe.
   Reden: trust layer voor enterprise buyers.

7. Breid Resource/Marketing Topic FAQ's uit met buyer intent.
   Reden: betere verbinding tussen education en solution pages.

8. Maak blog FAQ editorial rules verplicht voor BOFU en solution content.
   Reden: schaalbare FAQ kwaliteit.

9. Voeg homepage FAQ toe.
   Reden: category/entity clarity; belangrijk, maar minder direct high-intent dan solutions/platform.

## Implementatie Roadmap

### Fase 1: Template Foundation

- Maak een gedeelde public FAQ partial, bijvoorbeeld `resources/views/public/partials/faq.blade.php`.
- Input: `items`, `eyebrow`, `title`, `intro`, `variant`.
- Render zichtbare vraag/antwoordblokken.
- Genereer FAQPage JSON-LD alleen wanneer items zichtbaar zijn.
- Sta optioneel interne links toe via veilige HTML of structured link fields.

### Fase 2: Data Model per Paginatype

- Homepage/product/company pages: voeg `faq` toe aan `lang/en/public.php` en `lang/nl/public.php`.
- Solution pages: voeg `faq` toe aan de copy arrays in `PublicSolutionController`.
- Market pages: voeg `faq` toe per item in `config/argusly_markets.php`.
- Pricing: hergebruik bestaande `$pageContent['faq']` voor zichtbare FAQ en JSON-LD.
- Security/legal: voeg optionele FAQ toe aan public translation arrays of legal page payload.

### Fase 3: High-Impact Content Rollout

- Publiceer eerst AI Visibility, Opportunity Intelligence, Product Platform en Pricing JSON-LD.
- Daarna Competitive Intelligence en Marketing Without A Large Team.
- Daarna market pages met vertical blueprint.
- Daarna Contact en Security.

### Fase 4: Internal Linking

- Voeg per FAQ item optionele `links` of `cta_route` metadata toe.
- Link in antwoorden naar canonical localized URLs via `LocalizedMarketingUrl`.
- Prioriteer links naar AI Visibility, Opportunity Intelligence, Agentic Marketing, Platform, Pricing, Contact en Security.

### Fase 5: Editorial Governance

- Maak een FAQ ownership map zodat dezelfde primaire vraag niet op meerdere pagina's generiek terugkomt.
- Voeg checklist toe voor nieuwe pages/posts:
  - Heeft de pagina buyer questions?
  - Is FAQPage JSON-LD toegestaan en zichtbaar?
  - Welke interne links horen in de antwoorden?
  - Welke CTA wordt door FAQ ondersteund?
- Voeg test toe die voor pages met FAQ controleert dat FAQPage JSON-LD dezelfde vragen bevat als de zichtbare FAQ.

### Fase 6: Measurement

- Meet FAQ impact via:
  - Search Console impressions/clicks op question queries.
  - AI visibility prompt coverage.
  - CTA clicks vanuit FAQ-secties.
  - Contact form subjects na FAQ toevoeging.
  - Engagement op resource/blog FAQ sections.

## Direct Implementeerbare Backlog

1. `resources/views/public/pricing.blade.php`
   - Voeg FAQPage JSON-LD toe op basis van `$faqItems`.
   - Filter lege questions/answers.

2. `resources/views/public/solution.blade.php`
   - Voeg onder related paths of voor CTA een FAQ partial toe.
   - Render alleen wanneer `$copy['faq']` bestaat.

3. `app/Http/Controllers/PublicSolutionController.php`
   - Voeg per solution 8 FAQ items toe in EN/NL.
   - Houd overlap beperkt door solution-specifieke vragen.

4. `resources/views/public/market.blade.php`
   - Voeg FAQ partial toe voor de final CTA.
   - Render market-specific FAQ's uit config.

5. `config/argusly_markets.php`
   - Voeg per market 8 vertical FAQ items toe.
   - Gebruik sector-specifieke terminology.

6. `lang/en/public.php` en `lang/nl/public.php`
   - Voeg FAQ arrays toe voor homepage, product overview/platform, contact en security.

7. `resources/views/public/page.blade.php`
   - Geef FAQ data door aan product/company/security partials of render generiek op basis van page payload.

8. `resources/views/public/marketing-topic.blade.php`
   - Voeg interne linkvelden toe aan resource FAQ antwoorden.

9. Blog content governance
   - Maak FAQ schema verplicht voor BOFU/solution/comparison posts.
   - Geen FAQ op index/category/tag/RSS.

## Classificatie Samenvatting

- A: Agentic Marketing.
- B: Pricing, Resource marketing topics, Blog detail template.
- C: Homepage, Product Overview, Product Platform, alle Solution pages, alle Market pages, Contact, Security.
- D: Product legacy redirects, inactive Product Updates resource, Blog listings/RSS, Cookies, Legal hub. Privacy/Terms/Subprocessors zijn D tenzij enterprise salesfrictie FAQ's noodzakelijk maakt.
