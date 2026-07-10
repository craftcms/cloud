/** global: Craft */
/** global: picturefill */

(() => {
  const thumbSelector = '.thumb[data-srcset], .elementthumb[data-srcset]';
  let supportedExtensions;

  function getSupportedExtensions() {
    if (supportedExtensions) {
      return supportedExtensions;
    }

    supportedExtensions = new Set();

    Object.values(window.Craft?.fileKinds || {}).forEach((fileKind) => {
      (fileKind.extensions || []).forEach((extension) => {
        supportedExtensions.add(extension.toLowerCase());
      });
    });

    return supportedExtensions;
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

  function getIconUrl(image, thumb) {
    const sourceUrl =
      image.currentSrc ||
      image.src ||
      getFirstSrcsetUrl(thumb.dataset.srcset || '');
    const extension = sourceUrl ? getExtension(sourceUrl) : null;

    if (
      !extension ||
      !getSupportedExtensions().has(extension) ||
      typeof window.Craft?.getActionUrl !== 'function'
    ) {
      return null;
    }

    return window.Craft.getActionUrl('assets/icon', {
      extension,
    });
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
      const image = event.target;

      if (
        !(image instanceof HTMLImageElement) ||
        image.dataset.cloudAssetThumbFallback === 'true'
      ) {
        return;
      }

      const thumb = image.closest(thumbSelector);
      const iconUrl = thumb ? getIconUrl(image, thumb) : null;

      if (!thumb || !iconUrl) {
        return;
      }

      applyFallback(image, thumb, iconUrl);
    },
    true,
  );
})();
