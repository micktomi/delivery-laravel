<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <title>Η παραγγελία σας καταχωρίστηκε</title>
</head>
<body>
    <h2>Η παραγγελία σας καταχωρίστηκε επιτυχώς.</h2>

    <p>
        Η παραγγελία σας προχωρά κανονικά.
    </p>

    <p>
        <strong>Αριθμός παραγγελίας:</strong>
        #{{ $order->display_number }}
    </p>

    <p>
        <strong>Σύνολο:</strong>
        {{ number_format((float) $order->total, 2, ',', '.') }} €
    </p>

    <p>Ευχαριστούμε για την παραγγελία σας.</p>
</body>
</html>
