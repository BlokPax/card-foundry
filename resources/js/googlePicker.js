// Google Picker glue: the Livewire component dispatches `open-google-picker`
// with the signed-in user's access token; we lazy-load Google's picker
// script, show the Drive spreadsheet browser, and report the picked file
// back as a `sheet-picked` Livewire event.
//
// The picker is the only verification-free way to browse a user's sheets:
// listing files server-side needs restricted Drive scopes, while the picker
// runs on the (non-sensitive) drive.file scope and grants the app access to
// exactly the file the user picks.

let apiPromise = null;

function loadPickerApi() {
    apiPromise ??= new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = 'https://apis.google.com/js/api.js';
        script.onload = () => window.gapi.load('picker', { callback: resolve });
        script.onerror = () => {
            apiPromise = null;
            reject(new Error('Could not load the Google Picker (offline?).'));
        };
        document.head.appendChild(script);
    });

    return apiPromise;
}

export function pickedSheet(data, google) {
    if (data.action !== google.picker.Action.PICKED || !data.docs?.length) {
        return null;
    }

    const doc = data.docs[0];

    return { id: doc.id, name: doc.name ?? '' };
}

async function openPicker({ token, apiKey, appId }, Livewire) {
    await loadPickerApi();
    const google = window.google;

    const view = new google.picker.DocsView(google.picker.ViewId.SPREADSHEETS)
        .setMode(google.picker.DocsViewMode.LIST)
        .setIncludeFolders(false);

    const builder = new google.picker.PickerBuilder()
        .setTitle('Choose a merge sheet')
        .setOAuthToken(token)
        .setDeveloperKey(apiKey)
        .addView(view)
        .setCallback((data) => {
            const picked = pickedSheet(data, google);
            if (picked) {
                Livewire.dispatch('sheet-picked', picked);
            }
        });

    if (appId) {
        builder.setAppId(appId);
    }

    builder.build().setVisible(true);
}

export function initGooglePicker() {
    document.addEventListener('livewire:init', () => {
        window.Livewire.on('open-google-picker', (detail) => {
            openPicker(detail, window.Livewire).catch((e) => {
                console.error('google picker:', e);
                alert(e.message ?? 'Could not open the Google Picker.');
            });
        });
    });
}
