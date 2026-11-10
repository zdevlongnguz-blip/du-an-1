<script setup>
import { onMounted, ref } from 'vue'
import api from '../helpers/api/client'

const products = ref([])

const fetchApi = async () => {
  try {
    const { data } = await api.get('/?action=api-products')
    products.value = Array.isArray(data?.products?.data) ? data.products.data : []
    console.log('Fetched products:', products.value)
  } catch (err) {
    console.error('Error fetching products:', err)
  }
}

onMounted(fetchApi)
</script>

<template>
    <div>
        <h1>Test View</h1>
        <button @click="fetchApi">Fetch Products</button>
        <ul>
        <li v-for="product in products" :key="product.id">{{ product.name }}</li>
        </ul>
    </div>
</template>