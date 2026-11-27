/*
 * État des lieux sur tablette (phase 5, lot L6, D-06, D44, D45).
 *
 * - Signature : signature_pad (MIT) dessine sur un canevas ; l'image PNG est envoyée
 *   au serveur, qui la ré-encode (ImageReencoder) avant de la ranger sur le disque privé.
 * - Photos : compressées dans le navigateur (plus grand côté 1 600 px, JPEG 0,75) avant
 *   l'envoi, pour économiser la bande passante de l'agence ; le serveur les contrôle et
 *   les ré-encode de nouveau (aucune confiance dans le fichier reçu).
 * - Schéma : un toucher sur le véhicule donne la position du dommage en pourcentage.
 *
 * Aucun calcul ni aucune règle ici : tout est décidé par le domaine (RG-18).
 */
import SignaturePad from 'signature_pad';

const MAX_SIDE = 1600;
const QUALITY = 0.75;

async function compressImage(file) {
    const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
    const scale = Math.min(1, MAX_SIDE / Math.max(bitmap.width, bitmap.height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(bitmap.width * scale));
    canvas.height = Math.max(1, Math.round(bitmap.height * scale));
    canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    bitmap.close?.();

    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', QUALITY));

    return new File([blob], 'photo.jpg', { type: 'image/jpeg' });
}

function signatureField(wire) {
    return {
        pad: null,
        empty: true,
        saving: false,
        init() {
            const canvas = this.$refs.canvas;
            const resize = () => {
                const ratio = Math.max(window.devicePixelRatio || 1, 1);
                const data = this.pad?.toData() ?? [];
                canvas.width = canvas.offsetWidth * ratio;
                canvas.height = canvas.offsetHeight * ratio;
                canvas.getContext('2d').scale(ratio, ratio);
                this.pad?.clear();
                this.pad?.fromData(data);
            };

            this.pad = new SignaturePad(canvas, { backgroundColor: 'rgba(255,255,255,0)', penColor: '#111827' });
            this.pad.addEventListener('endStroke', () => { this.empty = this.pad.isEmpty(); });
            resize();
            window.addEventListener('resize', resize);
        },
        clear() {
            this.pad.clear();
            this.empty = true;
        },
        async save() {
            if (this.pad.isEmpty()) {
                return;
            }

            this.saving = true;

            try {
                await wire.saveSignature(this.pad.toDataURL('image/png'));
                this.clear();
            } finally {
                this.saving = false;
            }
        },
    };
}

function photoField(wire) {
    return {
        busy: false,
        error: null,
        async pick(event) {
            const files = Array.from(event.target.files ?? []);
            event.target.value = '';
            this.error = null;
            this.busy = true;

            try {
                for (const file of files) {
                    const compressed = await compressImage(file);
                    await new Promise((resolve, reject) => {
                        wire.upload('photo', compressed, resolve, () => reject(new Error('Envoi interrompu')), () => {});
                    });
                    await wire.storePhoto();
                }
            } catch (error) {
                this.error = 'La photo n\'a pas pu être envoyée : réessayez.';
            } finally {
                this.busy = false;
            }
        },
    };
}

function damageSchema(wire) {
    return {
        place(event, zone) {
            const box = this.$refs.schema.getBoundingClientRect();
            const x = Math.min(100, Math.max(0, ((event.clientX - box.left) / box.width) * 100));
            const y = Math.min(100, Math.max(0, ((event.clientY - box.top) / box.height) * 100));

            wire.set('zone', zone, false);
            wire.set('x', x.toFixed(2), false);
            wire.set('y', y.toFixed(2), false);
            wire.set('placing', true);
        },
    };
}

window.aviatorInspection = { signatureField, photoField, damageSchema };
