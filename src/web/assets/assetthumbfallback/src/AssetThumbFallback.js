/** global: Craft */
/** global: picturefill */

const thumbSelector = '.thumb[data-srcset], .elementthumb[data-srcset]';

function extensionFromUrl(url) {
  try {
    return new URL(url, window.location.href).pathname
      .match(/\.([a-z0-9_]+)$/i)?.[1]
      ?.toLowerCase();
  } catch (error) {
    return null;
  }
}

document.addEventListener(
  'error',
  (event) => {
    const image = event.target;

    if (
      !(image instanceof HTMLImageElement) ||
      image.dataset.cloudAssetThumbFallback === 'true' ||
      typeof window.Craft?.getActionUrl !== 'function'
    ) {
      return;
    }

    const thumb = image.closest(thumbSelector);

    if (!thumb) {
      return;
    }

    const sourceUrl =
      image.currentSrc ||
      image.src ||
      thumb.dataset.srcset?.match(/^\s*([^,\s]+)/)?.[1];
    const extension = sourceUrl ? extensionFromUrl(sourceUrl) : null;

    if (!extension) {
      return;
    }

    const iconUrl = window.Craft.getActionUrl('assets/icon', {extension});
    image.dataset.cloudAssetThumbFallback = 'true';
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
  },
  true,
);
