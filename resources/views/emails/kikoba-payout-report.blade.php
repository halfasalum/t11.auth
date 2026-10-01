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
                    <td>Thamani ya Hisa (Shares):</td>
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
