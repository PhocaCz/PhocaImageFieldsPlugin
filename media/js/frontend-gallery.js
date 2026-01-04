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

        lightbox.on('uiRegister', function () {
            lightbox.pswp.ui.registerElement({
                name: 'custom-caption',
                order: 9,
                isSettable: true,
                appendTo: 'root',
                onInit: (el, pswp) => {
                    pswp.on('change', () => {
                        const currSlideElement = pswp.currSlide.data.element;
                        let captionHTML = '';
                        if (currSlideElement) {
                            captionHTML = currSlideElement.getAttribute('data-pswp-caption') || '';
                        }
                        el.innerHTML = '<span>' + captionHTML + '</span>';
                    });
                }
            });
        });

        lightbox.init();
    });
});
