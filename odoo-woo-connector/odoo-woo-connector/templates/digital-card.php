<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Grupo Flores | Asesores Comerciales</title>
<meta name="theme-color" content="#0047ba">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,400;0,500;0,600;0,700;0,800;0,900;1,400&display=swap" rel="stylesheet">

<style>
/* ── Reset ── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }
body {
    font-family: 'Inter', system-ui, sans-serif;
    min-height: 100dvh;
    background: #0047ba;
    color: #fff;
    display: flex;
    flex-direction: column;
    align-items: center;
}

/* ── Page wrapper ── */
.page {
    position: relative;
    z-index: 1;
    width: 100%;
    max-width: 480px;
    padding: 52px 20px 60px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0;
}

/* ── Logo / Avatar area ── */
.logo-wrap {
    width: 104px;
    height: 104px;
    border-radius: 50%;
    background: #ffffff;
    border: 3px solid rgba(255,255,255,0.9);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    box-shadow:
        0 8px 30px rgba(0,0,0,0.25),
        0 0 0 5px rgba(255,255,255,0.15),
        0 0 0 8px rgba(243,112,33,0.12);
    margin-bottom: 20px;
}
.logo-wrap img {
    width: 80px;
    height: 80px;
    object-fit: contain;
}

/* ── Header text ── */
.badge {
    display: inline-block;
    background: rgba(243,112,33,0.25);
    border: 1px solid rgba(243,112,33,0.55);
    color: #ffc48a;
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 2.5px;
    text-transform: uppercase;
    padding: 4px 13px;
    border-radius: 99px;
    margin-bottom: 14px;
}
.headline {
    font-size: clamp(22px, 6vw, 28px);
    font-weight: 800;
    text-align: center;
    line-height: 1.2;
    letter-spacing: -0.5px;
    margin-bottom: 10px;
    color: #fff;
}
.headline em {
    font-style: normal;
    color: #f37021;
}
.subline {
    font-size: 13px;
    color: rgba(255,255,255,0.5);
    text-align: center;
    max-width: 300px;
    line-height: 1.55;
    margin-bottom: 36px;
}

/* ── Divider ── */
.divider {
    width: 40px;
    height: 3px;
    background: linear-gradient(90deg, #0047ba, #f37021);
    border-radius: 2px;
    margin: 0 auto 36px;
}

/* ── Advisor pills ── */
.advisors {
    width: 100%;
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-bottom: 24px;
}

.pill {
    display: flex;
    align-items: center;
    gap: 14px;
    width: 100%;
    min-height: 68px;
    padding: 12px 18px;
    background: rgba(255,255,255,0.10);
    border: 1px solid rgba(255,255,255,0.18);
    border-radius: 16px;
    text-decoration: none;
    color: #fff;
    backdrop-filter: blur(12px);
    transition:
        background  0.2s ease,
        border-color 0.2s ease,
        transform   0.22s cubic-bezier(0.175,0.885,0.32,1.275),
        box-shadow  0.2s ease;
    position: relative;
    overflow: hidden;
}
.pill::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, rgba(0,71,186,0.18), transparent 60%);
    opacity: 0;
    transition: opacity 0.2s;
    pointer-events: none;
}
.pill:hover {
    background: rgba(255,255,255,0.18);
    border-color: rgba(243,112,33,0.45);
    transform: translateY(-3px) scale(1.01);
    box-shadow: 0 10px 28px rgba(0,0,0,0.2), 0 0 0 1px rgba(243,112,33,0.2);
}
.pill:hover::after { opacity: 1; }

/* avatar inside pill */
.pill-avatar {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    flex-shrink: 0;
    overflow: hidden;
    border: 2px solid rgba(255,255,255,0.30);
    background: linear-gradient(135deg, rgba(243,112,33,0.7) 0%, rgba(0,71,186,0.7) 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 17px;
    font-weight: 800;
    color: #fff;
    box-shadow: 0 3px 10px rgba(0,0,0,0.2);
    transition: border-color 0.2s, box-shadow 0.2s;
}
.pill-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.pill:hover .pill-avatar {
    border-color: rgba(243,112,33,0.7);
    box-shadow: 0 0 0 3px rgba(243,112,33,0.2);
}

.pill-info {
    flex: 1;
    min-width: 0;
}
.pill-name {
    font-size: 14px;
    font-weight: 700;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    line-height: 1.2;
}
.pill-role {
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: rgba(255,255,255,0.4);
    margin: 3px 0 2px;
}
.pill-phone {
    font-size: 11px;
    color: rgba(255,255,255,0.6);
    font-weight: 500;
}

/* WhatsApp icon pill */
.wa-chip {
    display: flex;
    align-items: center;
    gap: 5px;
    background: #25D366;
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    padding: 6px 12px;
    border-radius: 99px;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(37,211,102,0.3);
    transition: background 0.2s, transform 0.2s;
    white-space: nowrap;
}
.wa-chip svg { width: 13px; height: 13px; fill: currentColor; }
.pill:hover .wa-chip { background: #1da851; transform: scale(1.05); }

/* ── CTA button ── */
.cta {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    min-height: 56px;
    background: linear-gradient(135deg, #f37021 0%, #d95e0e 100%);
    color: #fff;
    text-decoration: none;
    font-weight: 800;
    font-size: 14px;
    letter-spacing: 0.3px;
    border-radius: 16px;
    box-shadow: 0 8px 24px rgba(243,112,33,0.35);
    transition: transform 0.2s, box-shadow 0.2s, filter 0.2s;
    margin-bottom: 36px;
}
.cta svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.cta:hover { transform: translateY(-2px); box-shadow: 0 12px 32px rgba(243,112,33,0.5); filter: brightness(1.06); }

/* ── Footer ── */
.footer {
    text-align: center;
    color: rgba(255,255,255,0.85);
    font-size: 11px;
    letter-spacing: 0.3px;
    line-height: 1.8;
}
.footer a { color: #fff; text-decoration: none; font-weight: 700; }
.footer a:hover { text-decoration: underline; }

/* ── Desktop QR Floating ── */
.qr-desktop { display: none; }

/* ── Responsive ── */
@media (min-width: 900px) {
    body { padding: 40px 0; justify-content: center; }
    .page {
        margin: auto;
        border: 1px solid rgba(255,255,255,0.15);
        border-radius: 28px;
        background: rgba(255,255,255,0.05);
        backdrop-filter: blur(20px);
        box-shadow: 0 30px 80px rgba(0,0,60,0.35);
    }
    .qr-desktop {
        display: flex;
        flex-direction: column;
        align-items: center;
        background: rgba(255,255,255,0.1);
        padding: 20px;
        border-radius: 16px;
        backdrop-filter: blur(20px);
        border: 1px solid rgba(255,255,255,0.2);
        box-shadow: 0 15px 40px rgba(0,0,60,0.25);
        text-align: center;
        width: 180px;
        position: fixed;
        right: 40px;
        bottom: 40px;
        z-index: 100;
    }
    .qr-desktop h3 {
        font-size: 14px;
        font-weight: 700;
        margin-bottom: 5px;
    }
    .qr-desktop p {
        font-size: 11px;
        color: rgba(255,255,255,0.85);
        margin-bottom: 12px;
        line-height: 1.4;
    }
    .qr-desktop img {
        width: 120px;
        height: 120px;
        border-radius: 8px;
        background: #fff;
        padding: 6px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.15);
    }
}
</style>
</head>
<body>

<?php
$current_url = home_url('/?owc_tarjeta_digital=1');
$qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&margin=0&data=' . urlencode($current_url);
?>

<!-- QR Card for Desktop (Floating) -->
<div class="qr-desktop">
    <h3>Llévanos en tu celular</h3>
    <p>Escanea este código para abrir la tarjeta en tu teléfono.</p>
    <img src="<?php echo esc_url($qr_url); ?>" alt="QR Tarjeta Digital">
</div>

<div class="page">

    <!-- Logo -->
    <div class="logo-wrap">
        <img src="https://grupofloressa.com/wp-content/uploads/2025/05/logo-grupo-flores-transparente.webp"
             alt="Grupo Flores"
             onerror="this.style.display='none'">
    </div>

    <!-- Header -->
    <div class="badge">Equipo Comercial</div>
    <h1 class="headline">¿Con quién te <em>comunicamos?</em></h1>
    <p class="subline">Elige al asesor de tu preferencia y contáctalo directamente por WhatsApp</p>
    <div class="divider"></div>

    <!-- Advisor pills -->
    <div class="advisors">
        <?php
        $wa_svg = '<svg viewBox="0 0 448 512"><path d="M380.9 97.1C339 55.1 283.2 32 223.9 32c-122.4 0-222 99.6-222 222 0 39.1 10.2 77.3 29.6 111L0 480l117.7-30.9c32.4 17.7 68.9 27 106 27h.1c122.3 0 224.1-99.6 224.1-222 0-59.3-25.2-115-67.1-157zm-157 341.6c-33.2 0-65.7-8.9-94-25.8l-6.7-4-69.8 18.3L72 359.2l-4.4-7c-18.5-29.4-28.2-63.4-28.2-98.2 0-101.7 82.8-184.5 184.6-184.5 49.3 0 95.6 19.2 130.4 54.1 34.8 34.9 56.2 81.2 56.1 130.5 0 101.8-84.9 184.6-186.6 184.6zm101.2-138.2c-5.5-2.8-32.8-16.1-37.9-18-5.1-1.9-8.8-2.8-12.5 2.8-3.7 5.6-14.3 18-17.6 21.8-3.2 3.7-6.5 4.2-12 1.4-32.6-16.3-54-29.1-75.5-66-5.7-9.8 5.7-9.1 16.3-30.3 1.8-3.7.9-6.9-.5-9.7-1.4-2.8-12.5-30.1-17.1-41.2-4.5-10.8-9.1-9.3-12.5-9.5-3.2-.2-6.9-.2-10.6-.2-3.7 0-9.7 1.4-14.8 6.9-5.1 5.6-19.4 19-19.4 46.3 0 27.3 19.9 53.7 22.6 57.4 2.8 3.7 39.1 59.7 94.8 83.8 35.2 15.2 49 16.5 66.6 13.9 10.7-1.6 32.8-13.4 37.4-26.4 4.6-13 4.6-24.1 3.2-26.4-1.3-2.5-5-3.8-10.5-6.6z"/></svg>';

        $advisors = [];
        if ( class_exists('OWC_Settings') ) {
            $json    = OWC_Settings::get('checkout_advisors_json', '[]');
            $decoded = json_decode( $json, true );
            if ( is_array( $decoded ) ) {
                foreach ( $decoded as $a ) {
                    $name  = trim( $a['name']  ?? '' );
                    $phone = trim( $a['phone']  ?? '' );
                    $img   = trim( $a['img']    ?? '' );
                    if ( ! empty( $name ) ) {
                        $advisors[] = [ 'name' => $name, 'phone' => $phone, 'img' => $img ];
                    }
                }
            }
        }

        if ( empty( $advisors ) ) {
            echo '<p style="color:rgba(255,255,255,0.4);text-align:center;font-size:14px;">No hay asesores configurados aún.</p>';
        }

        foreach ( $advisors as $a ) :
            $name         = esc_html( $a['name'] );
            $phone        = esc_html( $a['phone'] );
            $img_url      = esc_url( $a['img'] );
            $initial      = strtoupper( mb_substr( $a['name'], 0, 1, 'UTF-8' ) );
            $clean_phone  = preg_replace( '/[^0-9]/', '', $a['phone'] );
            $wa_href      = ! empty( $clean_phone ) ? 'https://wa.me/' . $clean_phone : '#';
        ?>
        <a class="pill" href="<?php echo esc_url( $wa_href ); ?>" target="_blank" rel="noopener">

            <div class="pill-avatar">
                <?php if ( ! empty( $img_url ) ) : ?>
                    <img src="<?php echo $img_url; ?>"
                         alt="<?php echo $name; ?>"
                         onerror="this.style.display='none'; this.parentElement.innerHTML='<?php echo $initial; ?>';">
                <?php else : ?>
                    <?php echo $initial; ?>
                <?php endif; ?>
            </div>

            <div class="pill-info">
                <div class="pill-name"><?php echo $name; ?></div>
                <div class="pill-role">Asesor de ventas</div>
                <?php if ( ! empty( $phone ) ) : ?>
                    <div class="pill-phone"><?php echo $phone; ?></div>
                <?php endif; ?>
            </div>

            <div class="wa-chip">
                <?php echo $wa_svg; ?>
                WhatsApp
            </div>

        </a>
        <?php endforeach; ?>
    </div>

    <!-- CTA -->
    <a href="https://grupofloressa.com/tienda/" class="cta">
        <svg viewBox="0 0 24 24">
            <path d="M6 6h15l-1.5 9h-12z"/>
            <path d="M6 6L5 3H2"/>
            <circle cx="9" cy="20" r="1"/>
            <circle cx="18" cy="20" r="1"/>
        </svg>
        Ver catálogo completo
    </a>

    <!-- Footer -->
    <div class="footer">
        <a href="https://grupofloressa.com/">grupofloressa.com</a><br>
        © Grupo Flores · Construcción &amp; Ferretería · Perú
    </div>

</div>
</body>
</html>
