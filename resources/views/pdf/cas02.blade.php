<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>CAS-02 Claim for Indemnity</title>
    <style>
        @page {
            size: 8.5in 13in; /* Philippine Long / Legal Paper */
            margin: 20px 35px;
        }
        @media print {
            html, body {
                width: 8.5in;
                height: 13in;
            }
        }
        body {
            font-family: 'Times New Roman', Times, serif;
            font-size: 10px;
            color: #000;
            margin: 0;
            line-height: 1.2;
        }
        
        /* Top Header */
        .header-container {
            width: 100%;
            margin-bottom: 6px;
        }
        .form-code {
            float: right;
            text-align: right;
            font-size: 9.5px;
            font-weight: bold;
            line-height: 1.1;
        }
        .header-title {
            text-align: center;
        }
        .header-title h2 {
            margin: 0;
            font-size: 12px;
            font-weight: bold;
            letter-spacing: 0.2px;
        }
        .header-title .region {
            font-size: 10px;
            font-style: italic;
            margin: 1px 0;
        }
        .header-title h3 {
            margin: 2px 0 0 0;
            font-size: 12px;
            font-weight: bold;
        }
        .header-title .tagalog-title {
            font-weight: bold;
            font-size: 10px;
            margin: 0;
        }

        /* Date Line */
        .date-line {
            text-align: right;
            margin-bottom: 8px;
            font-size: 10px;
            font-weight: bold;
        }
        .date-line span.underline {
            display: inline-block;
            border-bottom: 1px solid #000;
            width: 150px;
            text-align: center;
            font-weight: normal;
        }

        /* Recipient & Intro Paragraphs */
        .to-table {
            width: 100%;
            margin-bottom: 6px;
        }
        .to-table td {
            vertical-align: top;
            padding: 0;
        }
        p {
            margin: 3px 0;
        }
        .italic {
            font-style: italic;
        }
        .bold {
            font-weight: bold;
        }

        /* Section Headers */
        .section-title {
            font-weight: bold;
            font-size: 10px;
            margin-top: 8px;
            margin-bottom: 3px;
        }

        /* Form Fields Layout */
        .field-table {
            width: 100%;
            border-collapse: collapse;
        }
        .field-table td {
            padding: 2px 0;
            vertical-align: bottom;
            font-size: 10px;
        }
        .num-col {
            width: 22px;
            vertical-align: top;
            text-align: right;
            padding-right: 5px !important;
            font-weight: bold;
        }
        .fill-line {
            border-bottom: 1px solid #000;
            display: inline-block;
            vertical-align: bottom;
            min-height: 12px;
            padding: 0 4px;
            box-sizing: border-box;
        }

        /* Section III Location Sketch Table */
        .sketch-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4px;
        }
        .sketch-table th, .sketch-table td {
            border: none;
            padding: 2px 3px;
            font-size: 10px;
        }
        .sketch-table th {
            text-align: center;
            font-weight: normal;
            width: 20%;
        }
        .sketch-line {
            border-bottom: 1px solid #000;
            display: block;
            width: 85%;
            margin: 0 auto;
            min-height: 12px;
            text-align: center;
        }

        /* Section IV Cost Table */
        .cost-table {
            width: 70%;
            margin-left: 22px;
            border-collapse: collapse;
            margin-top: 4px;
        }
        .cost-table td {
            border: none;
            padding: 2px 0;
            font-size: 10px;
        }
        .cost-amount {
            border-bottom: 1px solid #000;
            text-align: right;
            width: 140px;
        }

        /* Signature Section */
        .signature-block {
            margin-top: 14px;
            text-align: right;
        }
        .signature-container {
            display: inline-block;
            text-align: center;
            width: 280px;
        }
        .signature-space {
            height: 32px;
            text-align: center;
        }
        .signature-space img {
            max-height: 30px;
            max-width: 200px;
        }
        .signature-line {
            border-bottom: 1px solid #000;
            width: 100%;
            margin-bottom: 3px;
            font-weight: bold;
            text-align: center;
        }
        .signature-caption {
            font-size: 9.5px;
            font-weight: bold;
        }

        .note {
            margin-top: 8px;
            font-weight: bold;
            font-size: 9.5px;
        }

        /* Family Profile Section */
        .family-profile-title {
            text-align: center;
            font-weight: bold;
            font-size: 11px;
            margin-top: 10px;
            margin-bottom: 4px;
        }
        .fp-row {
            margin-bottom: 3px;
            font-size: 10px;
        }

        /* Household Table */
        table.household {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4px;
        }
        table.household th, table.household td {
            border: 1px solid #000;
            padding: 3px 5px;
            font-size: 9.5px;
        }
        table.household th {
            text-align: center;
            font-weight: normal;
        }
        table.household td.num-cell {
            width: 18px;
            text-align: center;
            border-right: none;
        }
        table.household td.name-cell {
            border-left: none;
        }
    </style>
</head>
<body>

    @php
        $report = $claim->damageReport ?? null;
        $app = $report->insuranceApplication ?? null;
        $farm = $app->farm ?? null;
        $farmerProfile = $farm->farmerProfile ?? null;
        $user = $farmerProfile->user ?? null;

        $farmerName = $user
            ? trim(implode(' ', array_filter([
                $user->first_name,
                $user->middle_name,
                $user->last_name,
                $user->extension_name,
            ])))
            : null;

        $cropType = strtolower($farm->crop_type ?? '');

        // Date formatter: accepts Carbon objects or ISO strings
        $fmtDate = function ($value) {
            if (empty($value)) {
                return '';
            }
            try {
                return \Carbon\Carbon::parse($value)->format('F j, Y');
            } catch (\Exception $e) {
                return '';
            }
        };

        // Embed the signature as base64 so DomPDF can render it
        $signatureData = null;
        if ($app && !empty($app->signature_path)) {
            $sigFile = storage_path('app/public/' . $app->signature_path);
            if (file_exists($sigFile)) {
                $sigExt = strtolower(pathinfo($sigFile, PATHINFO_EXTENSION));
                $signatureData = 'data:image/' . $sigExt . ';base64,' . base64_encode(file_get_contents($sigFile));
            }
        }
    @endphp

    <!-- Form Code Header -->
    <div class="form-code">
        CAS-02<br>
        2017/FEB
    </div>

    <!-- Header Section -->
    <div class="header-container">
        <div class="header-title">
            <h2>PHILIPPINE CROP INSURANCE CORPORATION</h2>
            <div class="region">{{ $app->region->name ?? 'Region II' }}</div>
            <h3>CLAIM FOR INDEMNITY</h3>
            <div class="tagalog-title">(PAGHAHABOL BAYAD)</div>
        </div>
    </div>

    <!-- Date Line -->
    <div class="date-line">
        DATE (PETSA) <span class="underline">{{ $fmtDate($claim->claim_filed_date ?? null) }}</span>
    </div>

    <!-- Recipient -->
    <table class="to-table">
        <tr>
            <td style="width: 25px;" class="bold">TO</td>
            <td style="width: 12px;" class="bold">:</td>
            <td class="bold">The Chief CAD<br>PCIC-RO</td>
        </tr>
    </table>

    <p style="padding-left: 37px;">
        Please send your Team of Adjusters to assess damage of my insured crop.<br>
        <span class="italic">(Mangyaring magpadala kayo ng tagapag-imbistiga upang tasahin ang naging pinsala ng aking pananim)</span>
    </p>

    <p style="padding-left: 37px;">
        Hereunder are the basic information needed by your office:<br>
        <span class="italic">(Narito ang mga kinakailangang tala ng iyong tanggapan:)</span>
    </p>

    <!-- SECTION I -->
    <div class="section-title">I. BASIC INFORMATION (MGA PANGUNAHING IMPORMASYON):</div>
    <table class="field-table">
        <tr>
            <td class="num-col">1.</td>
            <td style="white-space: nowrap;">Name of Farmer-Assured <span class="italic">(Pangalan ng Magsasaka)</span>:</td>
            <td style="width: 100%;"><span class="fill-line" style="width: 100%;">{{ $farmerName ?? '' }}</span></td>
        </tr>
        <tr>
            <td class="num-col">2.</td>
            <td style="white-space: nowrap;">Address <span class="italic">(Tirahan)</span>:</td>
            <td><span class="fill-line" style="width: 100%;">{{ $farmerProfile->address ?? '' }}</span></td>
        </tr>
        <tr>
            <td class="num-col">3.</td>
            <td style="white-space: nowrap;">Cell Phone Number <span class="italic">(Numero ng Telepono)</span>:</td>
            <td><span class="fill-line" style="width: 100%;">{{ $user->phone_number ?? '' }}</span></td>
        </tr>
        <tr>
            <td class="num-col">4.</td>
            <td style="white-space: nowrap;">Location of Farm <span class="italic">(Lugar ng Saka)</span>:</td>
            <td><span class="fill-line" style="width: 100%;">{{ $app->farm_location ?? '' }}</span></td>
        </tr>
        <tr>
            <td class="num-col">5.</td>
            <td colspan="2">
                Insured Crops <span class="italic">(Pananim na Ipinaseguro)</span>:
                &nbsp;&nbsp;&nbsp;&nbsp;( {{ $cropType === 'palay' || $cropType === 'rice' ? 'X' : ' ' }} ) Palay
                &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;( {{ $cropType === 'corn' ? 'X' : ' ' }} ) Corn
            </td>
        </tr>
        <tr>
            <td class="num-col">6.</td>
            <td colspan="2">
                Area Insured <span class="italic">(Luwang/Sukat ng Bukid na Ipinaseguro)</span>: 
                <span class="fill-line" style="width: 180px;">{{ isset($app->insured_area) ? number_format((float) $app->insured_area, 2) : '' }}</span> ha. <span class="italic">(ektarya)</span>
            </td>
        </tr>
        <tr>
            <td class="num-col">7.</td>
            <td style="white-space: nowrap;">Variety Planted <span class="italic">(Binhing Itinanim)</span>:</td>
            <td><span class="fill-line" style="width: 100%;">{{ $app->variety ?? '' }}</span></td>
        </tr>
        <tr>
            <td class="num-col">8.</td>
            <td colspan="2">
                <span class="italic">Petsa ng Pamumunla</span>: <span class="fill-line" style="width: 140px;">{{ $fmtDate($app->sowing_date ?? null) }}</span>
                &nbsp;&nbsp;&nbsp;&nbsp;<span class="bold">Aktuwal na Petsa ng Pagtatanim</span> <span class="fill-line" style="width: 140px;">{{ $fmtDate($app->transplanting_date ?? null) }}</span>
            </td>
        </tr>
        <tr>
            <td class="num-col">9.</td>
            <td style="white-space: nowrap;">CIC Number <span class="italic">(Numero ng CIC)</span>:</td>
            <td><span class="fill-line" style="width: 100%;">{{ $app->cic_number ?? '' }}</span></td>
        </tr>
        <tr>
            <td class="num-col">10.</td>
            <td style="white-space: nowrap;">Underwriter/Cooperative <span class="italic">(Pangalan ng Ahente o Kooperatiba)</span>:</td>
            <td><span class="fill-line" style="width: 100%;">{{ $app->underwriter ?? '' }}</span></td>
        </tr>
        <tr>
            <td class="num-col">11.</td>
            <td colspan="2">
                Program <span class="italic">(Programa)</span>:
                &nbsp;&nbsp;&nbsp;&nbsp;( &nbsp;) Regular
                &nbsp;&nbsp;&nbsp;&nbsp;( &nbsp;) Sikat Saka
                &nbsp;&nbsp;&nbsp;&nbsp;( &nbsp;) RSBSA
                &nbsp;&nbsp;&nbsp;&nbsp;( &nbsp;) APCP-CAP-PBD<br>
                &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;( &nbsp;) Punla
                &nbsp;&nbsp;&nbsp;&nbsp;( &nbsp;) Cooperate Rice Farming
                &nbsp;&nbsp;&nbsp;&nbsp;( &nbsp;) Others: <span class="fill-line" style="width: 150px;">&nbsp;</span>
            </td>
        </tr>
    </table>

    <!-- SECTION II -->
    <div class="section-title">II. DAMAGE INDICATORS (MGA IMPORMASYON TUNGKOL SA PINSALA)</div>
    <table class="field-table">
        <tr>
            <td class="num-col">1.</td>
            <td style="white-space: nowrap;">Cause of Loss <span class="italic">(Sanhi ng Pinsala)</span>:</td>
            <td style="width: 100%;"><span class="fill-line" style="width: 100%;">{{ $report->damage_cause ?? '' }}</span></td>
        </tr>
        <tr>
            <td class="num-col">2.</td>
            <td style="white-space: nowrap;">Date of Loss Occurrence <span class="italic">(Petsa ng Pinsala)</span>:</td>
            <td><span class="fill-line" style="width: 100%;">{{ $fmtDate($report->damage_date ?? null) }}</span></td>
        </tr>
        <tr>
            <td class="num-col">3.</td>
            <td style="white-space: nowrap;">Age/Stage of Cultivation at time of loss <span class="italic">(Edad ng Pananim ng Mapinsala)</span>:</td>
            <td><span class="fill-line" style="width: 100%;">{{ $claim->crop_stage_at_loss ?? '' }}</span></td>
        </tr>
        <tr>
            <td class="num-col">4.</td>
            <td colspan="2">
                Area Damaged <span class="italic">(Luwang o Sukat ng Napinsalang Bahagi)</span>: 
                <span class="fill-line" style="width: 140px;">{{ isset($claim->area_damaged) ? number_format((float) $claim->area_damaged, 2) : '' }}</span> ha. <span class="italic">(ektarya)</span>
            </td>
        </tr>
        <tr>
            <td class="num-col">5.</td>
            <td colspan="2">
                Extent/Degree of Damage <span class="italic">(Tindi o Porsyento ng Pinsala)</span>: 
                <span class="fill-line" style="width: 110px;">{{ isset($claim->degree_of_damage) ? number_format((float) $claim->degree_of_damage, 2) : '' }}</span> % <span class="italic">(porsyento)</span>
            </td>
        </tr>
        <tr>
            <td class="num-col">6.</td>
            <td style="white-space: nowrap;">Expected Date of Harvest <span class="italic">(Tinatayang Petsa ng Pagpapagapas o Pag-ani)</span>:</td>
            <td><span class="fill-line" style="width: 100%;">{{ $fmtDate($claim->expected_harvest_date ?? null) }}</span></td>
        </tr>
    </table>

    <!-- SECTION III -->
    <div class="section-title">
        III. LOCATION SKETCH PLAN OF DAMAGED INSURED CROPS (LSP)<br>
        <span class="italic" style="font-weight: normal;">(KROKIS NG BUKID NG MGA NASALANTANG NAKASEGURONG PANANIM)</span>
    </div>
    <p class="italic" style="font-weight: bold; margin-bottom: 2px;">Isulat ang pangalan ng mga may-ari/nagsasaka ng karatig na sakaha</p>

    <table class="sketch-table">
        <tr>
            <td style="width: 20%;"></td>
            <th>Lot 1 {{ isset($claim->area_damaged) ? number_format((float) $claim->area_damaged, 2) : '___' }} ha.</th>
            <th>Lot 2 ___ ha.</th>
            <th>Lot 3 ___ ha.</th>
            <th>Lot 4 ___ ha.</th>
        </tr>
        <tr>
            <td>1. North <span class="italic">(Hilaga)</span></td>
            <td><span class="sketch-line">{{ $app->north_boundary ?? '' }}&nbsp;</span></td>
            <td><span class="sketch-line">&nbsp;</span></td>
            <td><span class="sketch-line">&nbsp;</span></td>
            <td><span class="sketch-line">&nbsp;</span></td>
        </tr>
        <tr>
            <td>2. South <span class="italic">(Timog)</span></td>
            <td><span class="sketch-line">{{ $app->south_boundary ?? '' }}&nbsp;</span></td>
            <td><span class="sketch-line">&nbsp;</span></td>
            <td><span class="sketch-line">&nbsp;</span></td>
            <td><span class="sketch-line">&nbsp;</span></td>
        </tr>
        <tr>
            <td>3. East <span class="italic">(Silangan)</span></td>
            <td><span class="sketch-line">{{ $app->east_boundary ?? '' }}&nbsp;</span></td>
            <td><span class="sketch-line">&nbsp;</span></td>
            <td><span class="sketch-line">&nbsp;</span></td>
            <td><span class="sketch-line">&nbsp;</span></td>
        </tr>
        <tr>
            <td>4. West <span class="italic">(Kanluran)</span></td>
            <td><span class="sketch-line">{{ $app->west_boundary ?? '' }}&nbsp;</span></td>
            <td><span class="sketch-line">&nbsp;</span></td>
            <td><span class="sketch-line">&nbsp;</span></td>
            <td><span class="sketch-line">&nbsp;</span></td>
        </tr>
    </table>

    <!-- SECTION IV -->
    <div class="section-title">
        IV. COST OF PRODUCTION INPUTS AT THE TIME OF LOSS <span class="italic">(HALAGA NG MGA GINASTOS NANG MAPINSALA)</span>
    </div>
    <table class="cost-table">
        <tr>
            <td class="bold" style="width: 18px;">1.</td>
            <td>Land preparation <span class="italic">(Upa sa paggagayak ng bukid)</span></td>
            <td class="bold" style="width: 25px; text-align: right;">Php</td>
            <td class="cost-amount">{{ number_format($claim->cost_land_preparation ?? 0, 2) }}</td>
        </tr>
        <tr>
            <td class="bold">2.</td>
            <td>Cost of pulling of seedlings/transplanting <span class="italic">(Upa sa bunot at tanim)</span></td>
            <td></td>
            <td class="cost-amount">{{ number_format($claim->cost_seedling_transplanting ?? 0, 2) }}</td>
        </tr>
        <tr>
            <td class="bold">3.</td>
            <td>Cost of seeds <span class="italic">(Halaga ng binhi)</span></td>
            <td></td>
            <td class="cost-amount">{{ number_format($claim->cost_seeds ?? 0, 2) }}</td>
        </tr>
        <tr>
            <td class="bold">4.</td>
            <td>Cost of fertilizer <span class="italic">(Halaga ng abono)</span></td>
            <td></td>
            <td class="cost-amount">{{ number_format($claim->cost_fertilizer ?? 0, 2) }}</td>
        </tr>
        <tr>
            <td class="bold">5.</td>
            <td>Cost of chemicals <span class="italic">(Halaga ng kemikal)</span></td>
            <td></td>
            <td class="cost-amount">{{ number_format($claim->cost_chemicals ?? 0, 2) }}</td>
        </tr>
        <tr>
            <td class="bold">6.</td>
            <td>Others <span class="italic">(Iba pa.)</span></td>
            <td></td>
            <td class="cost-amount">{{ number_format($claim->cost_others ?? 0, 2) }}</td>
        </tr>
        <tr>
            <td></td>
            <td class="bold">TOTAL</td>
            <td></td>
            <td class="cost-amount bold">{{ number_format($claim->total_production_cost ?? 0, 2) }}</td>
        </tr>
    </table>

    <p style="margin-top: 8px;" class="bold">Thank you.</p>

    <!-- Signature Block -->
    <div class="signature-block">
        <div class="signature-container">
            <p style="margin-bottom: 4px; text-align: left;" class="bold">Very truly yours,</p>
            <div class="signature-space">
                @if ($signatureData)
                    <img src="{{ $signatureData }}" alt="Signature">
                @endif
            </div>
            <div class="signature-line">{{ $farmerName ?? '' }}&nbsp;</div>
            <div class="signature-caption">
                Signature over Printed Name of Assured Farmer-Claimant<br>
                <span class="italic">(Lagda sa ibabaw ng Pangalan ng Magsasakang Nakaseguro)</span>
            </div>
        </div>
    </div>

    <!-- Bottom Note -->
    <p class="note">Note: Accomplish in 3 copies(Gawin ng 3 kopya)</p>

    <!-- Farmer's Family Profile Section -->
    <div class="family-profile-title">Farmer's Family Profile</div>
    
    <div class="fp-row">
        <span class="bold">Name of insured:</span> <span class="fill-line" style="width: 310px;">{{ $farmerName ?? '' }}</span>
        &nbsp;&nbsp;&nbsp;&nbsp;<span class="bold">CP number:</span> <span class="fill-line" style="width: 190px;">{{ $user->phone_number ?? '' }}</span>
    </div>
    <div class="fp-row">
        <span class="bold">Address:</span> <span class="fill-line" style="width: 560px;">{{ $farmerProfile->address ?? '' }}</span>
    </div>
    <div class="fp-row bold" style="margin-bottom: 2px;">
        Household members:
    </div>

    <!-- Household Members Table -->
    <table class="household">
        <thead>
            <tr>
                <th colspan="2" style="width: 40%;">Name</th>
                <th style="width: 20%;">Birthday</th>
                <th style="width: 20%;">Civil Status</th>
                <th style="width: 20%;">Relationship</th>
            </tr>
        </thead>
        <tbody>
            @php $members = $claim->householdMembers ?? []; @endphp
            @for ($i = 0; $i < 3; $i++)
                <tr>
                    <td class="num-cell">{{ $i + 1 }}</td>
                    <td class="name-cell">{{ $members[$i]->name ?? '' }}</td>
                    <td>{{ $members[$i]->birthday ?? '' }}</td>
                    <td>{{ $members[$i]->civil_status ?? '' }}</td>
                    <td>{{ $members[$i]->relationship ?? '' }}</td>
                </tr>
            @endfor
        </tbody>
    </table>

</body>
</html>