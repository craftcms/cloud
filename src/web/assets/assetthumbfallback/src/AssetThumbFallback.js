/** global: Craft */
/** global: picturefill */

(() => {
  const thumbSelector = '.thumb[data-srcset], .elementthumb[data-srcset]';

  function getSettings() {
    return window.Craft?.CloudAssetThumbFallback;
  }

  function getFirstSrcsetUrl(srcset) {
    return srcset.match(/^\s*([^,\s]+)/)?.[1] || null;
  }

  function getExtension(url) {
    try {
      return new URL(url, window.location.href).pathname
        .match(/\.([a-z0-9_]+)$/i)?.[1]
        ?.toLowerCase();
    } catch (error) {
      return null;
    }
  }

  function getIconUrl(image, thumb, iconUrlsByExtension) {
    const sourceUrl =
      image.currentSrc ||
      image.src ||
      getFirstSrcsetUrl(thumb.dataset.srcset || '');
    const extension = sourceUrl ? getExtension(sourceUrl) : null;

    if (
      !extension ||
      !Object.prototype.hasOwnProperty.call(iconUrlsByExtension, extension)
    ) {
      return null;
    }

    return iconUrlsByExtension[extension];
  }

  function applyFallback(image, thumb, iconUrl) {
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
  }

  document.addEventListener(
    'error',
    (event) => {
      const settings = getSettings();
      const image = event.target;

      if (
        !settings?.iconUrlsByExtension ||
        !(image instanceof HTMLImageElement) ||
        image.dataset.cloudAssetThumbFallback === 'true'
      ) {
        return;
      }

      const thumb = image.closest(thumbSelector);
      const iconUrl = thumb
        ? getIconUrl(image, thumb, settings.iconUrlsByExtension)
        : null;

      if (!thumb || !iconUrl) {
        return;
      }

      applyFallback(image, thumb, iconUrl);
    },
    true,
  );
})();
