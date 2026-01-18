document.addEventListener("DOMContentLoaded", function () {

  var forms = document.querySelectorAll(".linora-product-filter");

  forms.forEach(function (form) {

    form.addEventListener("submit", function () {

      var inputs = form.querySelectorAll("input, select");

      inputs.forEach(function (input) {

        // Remove campos vazios
        if (
          (input.type === "text" || input.type === "number" || input.tagName === "SELECT") &&
          !input.value
        ) {
          input.removeAttribute("name");
        }

        // Remove radios e checkboxes não marcados
        if ((input.type === "radio" || input.type === "checkbox") && !input.checked) {
          input.removeAttribute("name");
        }

      });

    });

  });

  var clearBtn = document.getElementById("linora-clear-filters");

  if (!clearBtn) {
    console.log("Botão limpar filtros não encontrado");
    return;
  }

  clearBtn.addEventListener("click", function (e) {
    e.preventDefault();

    var url = new URL(window.location.href);

    // Lista de parâmetros que DEVEM SER REMOVIDOS (filtros)
    var paramsToRemove = [
      'filter_cat',
      'min_price',
      'max_price',
      'orderby'
    ];

    // Remove também qualquer parâmetro que comece com:
    // filter_pa_  (atributos)
    // filter_     (outros filtros futuros)
    url.searchParams.forEach(function (value, key) {
      if (
        key.startsWith('filter_') ||
        paramsToRemove.includes(key)
      ) {
        url.searchParams.delete(key);
      }
    });

    // Agora a URL ainda mantém:
    // s=caneta
    // e_search_props=...
    // etc

    var newUrl = url.origin + url.pathname;
    var queryString = url.searchParams.toString();

    if (queryString) {
      newUrl += "?" + queryString;
    }

    console.log("Redirecionando para:", newUrl);

    window.location.href = newUrl;
  });



});

function linoraSetPriceRange(min, max) {
  var minInput = document.getElementById('min_price');
  var maxInput = document.getElementById('max_price');

  if (!minInput || !maxInput) {
    console.warn('Inputs de preço não encontrados');
    return;
  }

  minInput.value = min;
  maxInput.value = max;

  console.log('Faixa de preço setada:', min, max);
}
