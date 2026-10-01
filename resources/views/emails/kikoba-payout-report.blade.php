<!DOCTYPE html>
<html>
<head>
    <title>Taarifa ya Malipo</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            background: #2E7D32;
            color: white;
            padding: 10px 20px;
            text-align: center;
            border-radius: 5px;
        }
        .content {
            background: #f9f9f9;
            padding: 20px;
            border-radius: 5px;
            margin-top: 20px;
        }
        .payout {
            background: #e7f6e9;
            padding: 15px;
            border-left: 3px solid #2E7D32;
            margin: 15px 0;
            text-align: center;
        }
        .payout .amount {
            font-size: 24px;
            font-weight: bold;
            color: #2E7D32;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        td {
            padding: 8px;
            border-bottom: 1px solid #ddd;
        }
        td:first-child {
            font-weight: bold;
            width: 55%;
        }
        .breakdown-table {
            margin-top: 10px;
            font-size: 13px;
        }
        .breakdown-table th {
            text-align: left;
            padding: 6px 8px;
            background: #eef1ef;
            border-bottom: 2px solid #ccc;
        }
        .breakdown-table td {
            padding: 6px 8px;
            font-weight: normal;
            width: auto;
            vertical-align: top;
        }
        .breakdown-table .text-end {
            text-align: right;
        }
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            color: white;
            background: #6c757d;
        }
        .badge.loan {
            background: #17a2b8;
        }
        .sub-line {
            font-size: 11px;
            color: #666;
        }
        .footer {
            text-align: center;
            margin-top: 20px;
            font-size: 12px;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>💰 Taarifa ya Malipo ya Kikoba</h2>
        </div>

        <div class="content">
            <p>Habari {{ $memberName }},</p>
            <p>
                Ripoti ya malipo ya mwisho ya kikundi <strong>{{ $groupName }}</strong>
                @if($financialYearName)
                    kwa mwaka wa fedha <strong>{{ $financialYearName }}</strong>
                @endif
                @if($periodLabel)
                    ({{ $periodLabel }})
                @endif
                imekamilika na kufungwa.
            </p>

            <div class="payout">
                <div>Jumla ya Malipo Yako</div>
                <div class="amount">TZS {{ number_format($totalPayout, 0) }}</div>
            </div>

            <table>
                <tr>
                    <td>Akiba (Savings):</td>
                    <td>TZS {{ number_format($totalSavingsAmount, 0) }}</td>
                </tr>
                <tr>
                    <td>Hisa (Share units):</td>
                    <td>{{ number_format($totalShareUnits, 0) }}</td>
                </tr>
                <tr>
                    <td>Thamani ya Hisa (Share amount):</td>
                    <td>TZS {{ number_format($totalShareAmount, 0) }}</td>
                </tr>
                <tr>
                    <td>Faida (Profit):</td>
                    <td>TZS {{ number_format($profitAmount, 0) }}</td>
                </tr>
                <tr>
                    <td>Jumla (Total Payout):</td>
                    <td><strong>TZS {{ number_format($totalPayout, 0) }}</strong></td>
                </tr>
            </table>

            @if(count($breakdown) > 0)
                <p style="margin-top: 20px; margin-bottom: 4px;"><strong>Jinsi Faida Ilivyogawanywa</strong></p>
                <table class="breakdown-table">
                    <thead>
                        <tr>
                            <th>Chanzo</th>
                            <th>Bidhaa</th>
                            <th>Mgawanyo</th>
                            <th class="text-end">Jumla</th>
                            <th class="text-end">Sehemu Yako</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($breakdown as $b)
                            <tr>
                                <td>
                                    <span class="badge {{ ($b['source'] ?? '') === 'loan_interest' ? 'loan' : '' }}">
                                        {{ ($b['source'] ?? '') === 'loan_interest' ? 'Riba ya Mkopo' : 'Mapato ya Bidhaa' }}
                                    </span>
                                </td>
                                <td>{{ $b['product_name'] ?? '' }}</td>
                                <td>
                                    @if(($b['calculation'] ?? '') === 'share_value')
                                        Kwa hisa
                                    @elseif(($b['calculation'] ?? '') === 'applicant_percentage')
                                        {{ $b['applicant_interest_percentage'] ?? 0 }}% kwa mwombaji + mgawanyo sawa
                                    @else
                                        Mgawanyo sawa
                                    @endif
                                </td>
                                <td class="text-end">TZS {{ number_format($b['pool_amount'] ?? 0, 0) }}</td>
                                <td class="text-end">
                                    <strong>TZS {{ number_format($b['member_share'] ?? 0, 0) }}</strong>
                                    @if(($b['calculation'] ?? '') === 'applicant_percentage')
                                        <div class="sub-line">
                                            Sehemu sawa: TZS {{ number_format($b['equal_share_amount'] ?? 0, 0) }}
                                            @if(($b['applicant_bonus_amount'] ?? 0) > 0)
                                                + Ziada ya mwombaji: TZS {{ number_format($b['applicant_bonus_amount'], 0) }}
                                                @if(!empty($b['applicant_bonus_loans']))
                                                    ({{ collect($b['applicant_bonus_loans'])->pluck('loan_number')->implode(', ') }})
                                                @endif
                                            @endif
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            <p style="margin-top: 20px;">
                Kwa maelezo zaidi kuhusu malipo haya, tafadhali wasiliana nasi.
            </p>

            <p style="margin-top: 20px;">
                Asante,<br>
                <strong>{{ $companyName }}</strong>
                @if($companyPhone)
                    <br>{{ $companyPhone }}
                @endif
            </p>
        </div>

        <div class="footer">
            <p>Hii ni taarifa ya moja kwa moja. Tafadhali usijibu barua pepe hii.</p>
        </div>
    </div>
</body>
</html>
