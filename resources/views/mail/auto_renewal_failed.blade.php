<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>هشدار: تمدید خودکار ناموفق</title>
    <style>
        body {
            font-family: Tahoma, Arial, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 20px;
            direction: rtl;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .header {
            background: linear-gradient(135deg, #f44336, #e57373);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
        }
        .content {
            padding: 30px;
        }
        .alert-box {
            background-color: #ffebee;
            border-right: 4px solid #f44336;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .alert-box strong {
            color: #c62828;
            font-size: 18px;
        }
        .info-box {
            background-color: #f5f5f5;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
        }
        .info-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e0e0e0;
        }
        .info-item:last-child {
            border-bottom: none;
        }
        .info-item span:first-child {
            color: #666;
        }
        .info-item span:last-child {
            font-weight: bold;
            color: #333;
        }
        .shortage {
            color: #f44336;
            font-weight: bold;
        }
        .warning {
            background-color: #fff3e0;
            border-right: 4px solid #ff9800;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .footer {
            background-color: #f5f5f5;
            padding: 20px;
            text-align: center;
            color: #666;
            font-size: 14px;
        }
        .button {
            display: inline-block;
            padding: 12px 30px;
            background-color: #f44336;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>⚠️ هشدار: تمدید خودکار ناموفق</h1>
        </div>
        
        <div class="content">
            <p>سلام {{ $name ?? 'کاربر گرامی' }}،</p>
            
            <div class="alert-box">
                <strong>🚨 موجودی حساب شما کافی نیست!</strong>
                <p>تمدید خودکار اشتراک شما به دلیل کمبود موجودی انجام نشد.</p>
            </div>
            
            <h3>📋 اطلاعات:</h3>
            <div class="info-box">
                <div class="info-item">
                    <span>📦 بسته:</span>
                    <span>{{ $plan_name ?? 'نامشخص' }}</span>
                </div>
                <div class="info-item">
                    <span>💰 قیمت بسته:</span>
                    <span>{{ $price ?? '0' }} تومان</span>
                </div>
                <div class="info-item">
                    <span>💳 موجودی فعلی:</span>
                    <span>{{ $balance ?? '0' }} تومان</span>
                </div>
                <div class="info-item">
                    <span>❌ کمبود:</span>
                    <span class="shortage">{{ $needed ?? '0' }} تومان</span>
                </div>
                <div class="info-item">
                    <span>🔄 دلیل تمدید:</span>
                    <span>{{ $reason ?? 'نامشخص' }}</span>
                </div>
            </div>

            @if(isset($was_expired) && $was_expired)
            <div class="warning">
                <strong>⏰ اشتراک شما منقضی شده است!</strong>
                <p>برای بازیابی سرویس، لطفاً هرچه سریعتر موجودی خود را شارژ کنید.</p>
            </div>
            @else
            <div class="warning">
                <strong>⚠️ تمدید خودکار غیرفعال شد!</strong>
                <p>تمدید خودکار اشتراک شما به طور موقت غیرفعال شده است.</p>
                @if(isset($days_left) && $days_left > 0)
                <p>زمان باقیمانده: {{ $days_left }} روز</p>
                @endif
            </div>
            @endif

            @if(isset($used_gb) && isset($total_gb))
            <p style="color: #666; font-size: 14px;">
                📈 حجم مصرفی: {{ $used_gb }} GB از {{ $total_gb }} GB ({{ $usage_percent ?? 0 }}%)
            </p>
            @endif

            <h3>📝 اقدامات لازم:</h3>
            <ol style="color: #666; line-height: 1.8;">
                <li>موجودی حساب خود را شارژ کنید</li>
                <li>تمدید خودکار را دوباره فعال کنید (در صورت نیاز)</li>
                <li>یا به صورت دستی اشتراک خود را تمدید کنید</li>
            </ol>

            <a href="{{ $app_url ?? '#' }}" class="button">شارژ حساب</a>
        </div>
        
        <div class="footer">
            <p>{{ $app_name ?? 'سرویس VPN' }}</p>
            <p>این ایمیل به صورت خودکار ارسال شده است.</p>
        </div>
    </div>
</body>
</html>
