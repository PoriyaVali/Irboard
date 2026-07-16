<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تمدید خودکار موفق</title>
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
            background: linear-gradient(135deg, #4caf50, #81c784);
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
            background-color: #e8f5e9;
            border-right: 4px solid #4caf50;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .alert-box strong {
            color: #2e7d32;
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
        .success {
            color: #4caf50;
            font-weight: bold;
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
            background-color: #4caf50;
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
            <h1>✅ تمدید خودکار موفق</h1>
        </div>
        
        <div class="content">
            <p>سلام {{ $name ?? 'کاربر گرامی' }}،</p>
            
            @if($was_expired ?? false)
            <div class="alert-box">
                <strong>🔄 بازیابی موفق!</strong>
                <p>اشتراک منقضی شده شما با موفقیت بازیابی و تمدید شد.</p>
            </div>
            @else
            <div class="alert-box">
                <strong>✅ تمدید خودکار انجام شد!</strong>
                <p>اشتراک شما با موفقیت تمدید شد و آماده استفاده است.</p>
            </div>
            @endif
            
            <h3>📋 اطلاعات تمدید:</h3>
            <div class="info-box">
                <div class="info-item">
                    <span>📦 بسته:</span>
                    <span>{{ $plan_name ?? 'نامشخص' }}</span>
                </div>
                <div class="info-item">
                    <span>💰 مبلغ پرداختی:</span>
                    <span class="success">{{ $price ?? '0' }} تومان</span>
                </div>
                <div class="info-item">
                    <span>💳 موجودی باقیمانده:</span>
                    <span>{{ $balance ?? '0' }} تومان</span>
                </div>
                <div class="info-item">
                    <span>📅 تاریخ انقضای جدید:</span>
                    <span>{{ $expired_at ?? 'نامشخص' }}</span>
                </div>
                <div class="info-item">
                    <span>📊 حجم جدید:</span>
                    <span>{{ $total_gb ?? '0' }} GB</span>
                </div>
                <div class="info-item">
                    <span>🔄 دلیل تمدید:</span>
                    <span>{{ $reason ?? 'نامشخص' }}</span>
                </div>
            </div>

            @if(isset($used_gb) && isset($total_gb))
            <p style="color: #666; font-size: 14px;">
                📈 حجم مصرفی قبلی: {{ $used_gb }} GB از {{ $total_gb }} GB ({{ $usage_percent ?? 0 }}%)
            </p>
            @endif

            <p style="margin-top: 30px;">
                اکنون می‌توانید از سرویس خود استفاده کنید.
            </p>

            <a href="{{ $app_url ?? '#' }}" class="button">ورود به پنل</a>
        </div>
        
        <div class="footer">
            <p>{{ $app_name ?? 'سرویس VPN' }}</p>
            <p>این ایمیل به صورت خودکار ارسال شده است.</p>
        </div>
    </div>
</body>
</html>
