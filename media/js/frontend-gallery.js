import PhotoSwipeLightbox from '../vendor/photoswipe/photoswipe-lightbox.esm.min.js';
import PhotoSwipe from '../vendor/photoswipe/photoswipe.esm.min.js';

document.addEventListener('DOMContentLoaded', () => {
    const galleries = document.querySelectorAll('.phocaimage-gallery');

    galleries.forEach(galleryElement => {
        const lightbox = new PhotoSwipeLightbox({
            gallery: galleryElement,
            children: 'a',
            pswpModule: PhotoSwipe
        });
        lightbox.init();
    });
});
