import Cropper from 'cropperjs';

/**
 * Media picker with up-front validation and an interactive cropper.
 *
 * Design decision: images that fall outside Instagram's accepted ratio are NOT
 * cropped silently. Auto-cropping can slice off faces or text without the
 * author noticing, so we open a cropper pre-set to the nearest valid ratio and
 * let them confirm — the same thing Instagram's own composer does.
 */
export const MEDIA_SPEC = {
    // Images
    minRatio: 0.8,          // 4:5 portrait
    maxRatio: 1.91,         // 1.91:1 landscape
    minWidth: 320,
    recommended: [
        { label: '1:1 Persegi', ratio: 1, size: '1080 × 1080' },
        { label: '4:5 Potret', ratio: 0.8, size: '1080 × 1350' },
        { label: '1.91:1 Lanskap', ratio: 1.91, size: '1200 × 628' },
    ],
    // Videos (Reels)
    videoMinSeconds: 3,
    videoMaxSeconds: 900,   // 15 minutes
    maxBytes: 100 * 1024 * 1024,
};

export function mediaPicker() {
    return {
        files: [],          // { name, url, isVideo, ok, issues[], width, height, duration }
        cropper: null,
        cropIndex: null,
        cropRatio: 1,
        spec: MEDIA_SPEC,

        /** Read every selected file, measure it, and flag problems. */
        async pick(event) {
            this.files = [];

            for (const file of Array.from(event.target.files)) {
                const isVideo = file.type.startsWith('video/');
                const url = URL.createObjectURL(file);

                const meta = isVideo
                    ? await this.readVideo(url)
                    : await this.readImage(url);

                this.files.push({
                    file,
                    name: file.name,
                    url,
                    isVideo,
                    size: file.size,
                    ...meta,
                    ...this.validate(file, isVideo, meta),
                });
            }
        },

        readImage(url) {
            return new Promise((resolve) => {
                const img = new Image();
                img.onload = () => resolve({ width: img.naturalWidth, height: img.naturalHeight });
                img.onerror = () => resolve({ width: 0, height: 0 });
                img.src = url;
            });
        },

        readVideo(url) {
            return new Promise((resolve) => {
                const video = document.createElement('video');
                video.preload = 'metadata';
                video.onloadedmetadata = () => resolve({
                    width: video.videoWidth,
                    height: video.videoHeight,
                    duration: Math.round(video.duration),
                });
                video.onerror = () => resolve({ width: 0, height: 0, duration: 0 });
                video.src = url;
            });
        },

        /** Human-readable problems, checked before the file ever leaves the browser. */
        validate(file, isVideo, meta) {
            const issues = [];

            if (file.size > MEDIA_SPEC.maxBytes) {
                issues.push(`Ukuran ${this.humanSize(file.size)} melebihi batas 100 MB.`);
            }

            if (isVideo) {
                if (meta.duration && meta.duration < MEDIA_SPEC.videoMinSeconds) {
                    issues.push(`Durasi ${meta.duration} detik terlalu pendek — minimal 3 detik.`);
                }
                if (meta.duration > MEDIA_SPEC.videoMaxSeconds) {
                    issues.push(`Durasi ${Math.round(meta.duration / 60)} menit terlalu panjang — maksimal 15 menit.`);
                }
            } else {
                const ratio = meta.height ? meta.width / meta.height : 0;

                if (meta.width < MEDIA_SPEC.minWidth) {
                    issues.push(`Lebar ${meta.width}px terlalu kecil — minimal ${MEDIA_SPEC.minWidth}px.`);
                }
                if (ratio && (ratio < MEDIA_SPEC.minRatio - 0.01 || ratio > MEDIA_SPEC.maxRatio + 0.01)) {
                    issues.push(
                        `Rasio ${ratio.toFixed(2)}:1 di luar rentang Instagram (4:5 sampai 1.91:1). Gunakan tombol Crop.`
                    );
                }
            }

            return { issues, ok: issues.length === 0 };
        },

        orientation(f) {
            if (!f.width || !f.height) return '-';
            if (f.width === f.height) return 'Persegi';

            return f.width > f.height ? 'Lanskap' : 'Potret';
        },

        humanSize(bytes) {
            const mb = bytes / 1048576;

            return mb >= 1 ? `${mb.toFixed(1)} MB` : `${Math.round(bytes / 1024)} KB`;
        },

        /* --- Cropping ---------------------------------------------------- */

        openCrop(index) {
            const target = this.files[index];
            if (!target || target.isVideo) return;

            this.cropIndex = index;

            // Start from the nearest valid ratio so one click is often enough.
            const ratio = target.width / target.height;
            this.cropRatio = ratio < 1 ? 0.8 : ratio > 1.4 ? 1.91 : 1;

            this.$nextTick(() => {
                const image = this.$refs.cropImage;
                image.src = target.url;

                this.cropper?.destroy();
                this.cropper = new Cropper(image, {
                    aspectRatio: this.cropRatio,
                    viewMode: 1,
                    autoCropArea: 1,
                    background: false,
                });
            });
        },

        setRatio(ratio) {
            this.cropRatio = ratio;
            this.cropper?.setAspectRatio(ratio);
        },

        applyCrop() {
            if (!this.cropper || this.cropIndex === null) return;

            const target = this.files[this.cropIndex];

            this.cropper.getCroppedCanvas({ imageSmoothingQuality: 'high' }).toBlob((blob) => {
                const cropped = new File([blob], target.name, { type: blob.type || 'image/jpeg' });

                // Swap the file inside the real <input> so the form submits the
                // cropped version — no server-side guessing required.
                const transfer = new DataTransfer();
                this.files.forEach((f, i) => transfer.items.add(i === this.cropIndex ? cropped : f.file));
                this.$refs.input.files = transfer.files;

                const url = URL.createObjectURL(cropped);
                this.readImage(url).then((meta) => {
                    Object.assign(target, {
                        file: cropped, url, size: cropped.size, ...meta,
                        ...this.validate(cropped, false, meta),
                    });
                    this.closeCrop();
                });
            }, 'image/jpeg', 0.92);
        },

        closeCrop() {
            this.cropper?.destroy();
            this.cropper = null;
            this.cropIndex = null;
        },

        get hasIssues() {
            return this.files.some((f) => !f.ok);
        },
    };
}
