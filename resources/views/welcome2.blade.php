<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Reporte</title>

    <!-- Fonts -->
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">

</head>

<body class="antialiased">
    <div class="relative flex justify-center min-h-screen py-4 bg-gray-100 items-top sm:items-center sm:pt-0"
        style="font-size: 2rem;">
        <div style="display: flex; flex-direction: column;">
            <button onclick="fetchReport()"
                style="display: none; padding: 13px 25px; cursor: pointer; background: white; border: 1px solid gray;"
                onmouseover="this.style.background='lightgray'; this.style.color='black';"
                onmouseout="this.style.background='white'; this.style.color='inherit';">Descargar
                Reporte</button>
            <div id="spinner" style="display: block; margin-top: 1rem; font-size: 14px;">
                Descargando...
            </div>
        </div>

    </div>
</body>

<script>
    function fetchReport() {
        const spinner = document.getElementById('spinner');
        spinner.style.display = 'block';

        const urlParams = new URLSearchParams(window.location.search);
        const sessionKey = urlParams.get('session_key');

        if (!sessionKey) {
            alert('Session key is missing in the URL');
            spinner.style.display = 'none';
            return;
        }

        fetch('speedup/generate', {
                method: 'GET',
                headers: {
                    'X-Hash-Token': sessionKey
                }
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }

                const disposition = response.headers.get('Content-Disposition');
                let filename = 'download.xlsx';

                if (disposition && disposition.includes('filename=')) {
                    const match = disposition.match(/filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/);
                    if (match && match[1]) {
                        filename = match[1].replace(/['"]/g, '');
                    }
                }

                return response.blob().then(blob => ({
                    blob,
                    filename
                }));
            })
            .then(({
                blob,
                filename
            }) => {
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.style.display = 'none';
                a.href = url;
                a.download = filename;
                document.body.appendChild(a);
                a.click();
                window.URL.revokeObjectURL(url);
            })
            .catch(error => {
                console.error('There was a problem with the fetch operation:', error);
                alert('Error downloading the report.');
            })
            .finally(() => {
                spinner.textContent = 'Descargado.';
            });
    }

    document.addEventListener('DOMContentLoaded', () => {
        const downloadButton = document.querySelector('button[onclick="fetchReport()"]');
        if (downloadButton) {
            downloadButton.click();
        }
    });
</script>

</html>
