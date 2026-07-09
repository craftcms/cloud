/** global: Craft */
/** global: picturefill */

(() => {
  const thumbSelector = '.thumb[data-srcset], .elementthumb[data-srcset]';

  function getSettings() {
    return window.Craft?.CloudAssetThumbFallback;
  }

  function isPdfThumbSrcset(srcset) {
    return /\.pdf(?:[?\s,]|$)/i.test(srcset);
  }

  function applyFallback(image, thumb, iconUrl) {
    image.dataset.cloudPdfThumbFallback = 'true';
    thumb.dataset.srcset = iconUrl;
    thumb.removeAttribute('data-animated');
    image.removeAttribute('data-animated');
    image.setAttribute('srcset', iconUrl);
    image.setAttribute('src', iconUrl);

    if (typeof picturefill === 'function') {
      picturefill({
        elements: [image],
      });
    }
  }

  document.addEventListener(
    'error',
    (event) => {
      const settings = getSettings();
      const image = event.target;

      if (
        !settings?.pdfIconUrl ||
        !(image instanceof HTMLImageElement) ||
        image.dataset.cloudPdfThumbFallback === 'true'
      ) {
        return;
      }

      const thumb = image.closest(thumbSelector);

      if (!thumb || !isPdfThumbSrcset(thumb.dataset.srcset || '')) {
        return;
      }

      applyFallback(image, thumb, settings.pdfIconUrl);
    },
    true,
  );
})();
